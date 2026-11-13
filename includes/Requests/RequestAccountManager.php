<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Block\BlockManager;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\Config\Config;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManager;
use MediaWiki\User\UserIdentity;
use Miraheze\MirahezeRequests\ConfigNames;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use StatusValue;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\ReadOnlyMode;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class RequestAccountManager extends RequestManager {

	private const TABLE = 'account_requests';

	public function __construct(
		private readonly MirahezeRequestsDatabaseService $dbService,
		private readonly UserFactory $userFactory,
		private readonly BlockManager $blockManager,
		private readonly Config $config,
		private readonly UserGroupManager $userGroupManager,
		private readonly ActorNormalization $actorNormalization,
		private readonly ReadOnlyMode $readOnlyMode,
	) {
		parent::__construct(
			'account',
			$this->dbService,
			$this->userFactory,
		);
	}

	/**
	 * Whether $user can see requesters' IP addresses. Controlled by
	 * $wgMirahezeRequestsIPVisibilityGroups: empty means nobody can,
	 * otherwise the user must be in one of the configured groups.
	 */
	public function canSeeIp( UserIdentity $user ): bool {
		$groups = $this->config->get( ConfigNames::IPVisibilityGroups );
		if ( !$groups ) {
			return false;
		}

		$userGroups = $this->userGroupManager->getUserEffectiveGroups( $user );
		return (bool)array_intersect( $groups, $userGroups );
	}

	/**
	 * Inserts a request waiting for its email address to be confirmed.
	 *
	 * @param array $row Columns from the form, including the timestamp
	 * @param ?UserIdentity $requester The account it was submitted from, or null if logged out
	 * @return array{0:int,1:string} The request ID and the plain confirmation token
	 */
	public function createUnconfirmed( array $row, ?UserIdentity $requester ): array {
		$token = self::newToken();
		$dbw = $this->dbService->getDbw();

		$dbw->newInsertQueryBuilder()
			->insertInto( self::TABLE )
			->row( [
				'request_actor' => $requester ? $this->actorNormalization->acquireActorId( $requester, $dbw ) : null,
				'request_token' => self::hashToken( $token ),
				'request_status' => MirahezeRequestsStatus::Unconfirmed->value,
				// BLOB columns can't have a default in MySQL
				'request_notes' => '',
			] + $row )
			->caller( __METHOD__ )
			->execute();

		return [ $dbw->insertId(), $token ];
	}

	public function deleteUnconfirmed( int $id ): void {
		$this->dbService->getDbw()->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [
				'request_id' => $id,
				'request_status' => MirahezeRequestsStatus::Unconfirmed->value,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * @return ?int The ID of the unconfirmed request whose unexpired link has this token
	 */
	public function findUnconfirmedIdByToken( string $token ): ?int {
		$dbw = $this->dbService->getDbw();

		$id = $dbw->newSelectQueryBuilder()
			->select( 'request_id' )
			->from( self::TABLE )
			->where( [
				'request_token' => self::hashToken( $token ),
				'request_status' => MirahezeRequestsStatus::Unconfirmed->value,
			] )
			->andWhere( $dbw->expr( 'request_timestamp', '>=', $this->getConfirmationCutoff( $dbw ) ) )
			->caller( __METHOD__ )
			->fetchField();

		return $id !== false ? (int)$id : null;
	}

	/**
	 * Puts the loaded unconfirmed request into the queue.
	 *
	 * @return StatusValue Fatal if already confirmed or another request has the name
	 */
	public function confirm(): StatusValue {
		$dbw = $this->dbService->getDbw();

		return $dbw->doAtomicSection( __METHOD__, function ( IDatabase $dbw, string $fname ): StatusValue {
			// Lock all requests for this name until commit to serialize confirmations.
			$requests = $dbw->newSelectQueryBuilder()
				->select( [ 'request_id', 'request_status' ] )
				->from( self::TABLE )
				->where( [ 'request_username' => $this->getUsername() ] )
				->orderBy( 'request_id' )
				->forUpdate()
				->caller( $fname )
				->fetchResultSet();

			$unconfirmed = false;
			$duplicate = false;
			foreach ( $requests as $request ) {
				if ( (int)$request->request_id === $this->getId() ) {
					$unconfirmed = $request->request_status === MirahezeRequestsStatus::Unconfirmed->value;
				} elseif ( in_array( $request->request_status, MirahezeRequestsStatus::OPEN, true ) ) {
					$duplicate = true;
				}
			}

			if ( !$unconfirmed ) {
				return StatusValue::newFatal( 'requestaccount-confirm-invalid' );
			}
			if ( $duplicate ) {
				return StatusValue::newFatal( 'requestaccount-error-duplicate-username' );
			}

			$dbw->newUpdateQueryBuilder()
				->update( self::TABLE )
				->set( [
					'request_status' => MirahezeRequestsStatus::Pending->value,
					'request_confirmed_timestamp' => $dbw->timestamp(),
					'request_token' => null,
				] )
				->where( [
					'request_id' => $this->getId(),
					'request_status' => MirahezeRequestsStatus::Unconfirmed->value,
				] )
				->caller( $fname )
				->execute();

			if ( $dbw->affectedRows() === 0 ) {
				return StatusValue::newFatal( 'requestaccount-confirm-invalid' );
			}

			$this->row->request_status = MirahezeRequestsStatus::Pending->value;
			return StatusValue::newGood();
		} );
	}

	/**
	 * Atomic, so two handlers can't act on one request.
	 *
	 * @return bool False if the loaded request is no longer in $from
	 */
	public function transitionStatus( string $from, string $to ): bool {
		return $this->updateRequest( [ 'request_status' => $to ], $from, __METHOD__ );
	}

	/** Record the handler and outcome, with a completion date only for closed requests. */
	public function resolve( string $status, UserIdentity $performer, string $notes ): void {
		$dbw = $this->dbService->getDbw();
		$timestamp = in_array( $status, MirahezeRequestsStatus::CLOSED, true ) ? $dbw->timestamp() : null;
		$this->updateRequest( [
			'request_status' => $status,
			'request_completed_timestamp' => $timestamp,
			'request_completed_actor' => $this->actorNormalization->acquireActorId( $performer, $dbw ),
			'request_notes' => $notes,
		], null, __METHOD__ );
	}

	/** Update delivery notes without changing who resolved the request or when. */
	public function setNotes( string $notes ): void {
		$this->updateRequest( [ 'request_notes' => $notes ], null, __METHOD__ );
	}

	/** Preserve the approving handler when delivery is retried. */
	public function finishApprovalMail( bool $success, string $notes ): void {
		$this->updateRequest( [
			'request_status' => $success ? MirahezeRequestsStatus::Complete->value :
				MirahezeRequestsStatus::MailFailed->value,
			'request_completed_timestamp' => $success ? $this->dbService->getDbw()->timestamp() : null,
			'request_notes' => $notes,
		], MirahezeRequestsStatus::Sending->value, __METHOD__ );
	}

	/** A failed status claim must not update the loaded row. */
	private function updateRequest( array $values, ?string $expectedStatus, string $caller ): bool {
		$dbw = $this->dbService->getDbw();
		$conditions = [ 'request_id' => $this->getId() ];
		if ( $expectedStatus !== null ) {
			$conditions['request_status'] = $expectedStatus;
		}

		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( $values )
			->where( $conditions )
			->caller( $caller )
			->execute();
		if ( $expectedStatus !== null && !$dbw->affectedRows() ) {
			return false;
		}

		foreach ( $values as $key => $value ) {
			$this->row->$key = $value;
		}
		return true;
	}

	public function hasOpenRequestForName( string $name ): bool {
		return (bool)$this->dbService->getDbw()->newSelectQueryBuilder()
			->select( 'request_id' )
			->from( self::TABLE )
			->where( [
				'request_username' => $name,
				'request_status' => MirahezeRequestsStatus::OPEN,
			] )
			->caller( __METHOD__ )
			->fetchField();
	}

	/** Whether the address is used by an open or unexpired unconfirmed request. */
	public function hasActiveRequestForEmail( string $email ): bool {
		$dbw = $this->dbService->getDbw();

		return (bool)$dbw->newSelectQueryBuilder()
			->select( 'request_id' )
			->from( self::TABLE )
			->where( [ 'request_email' => $email ] )
			->andWhere( $dbw->orExpr( [
				$dbw->expr( 'request_status', '=', MirahezeRequestsStatus::OPEN ),
				$dbw->expr( 'request_status', '=', MirahezeRequestsStatus::Unconfirmed->value )
					->and( 'request_timestamp', '>=', $this->getConfirmationCutoff( $dbw ) ),
			] ) )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Other requests with the same address as the loaded one, newest first.
	 *
	 * @param int $limit
	 * @return array<int,array{id:int,status:string}>
	 */
	public function getOtherRequestsWithSameEmail( int $limit ): array {
		$dbr = $this->dbService->getDbr();

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'request_id', 'request_status' ] )
			->from( self::TABLE )
			->where( [ 'request_email' => $this->row->request_email ] )
			->andWhere( [
				$dbr->expr( 'request_id', '!=', $this->getId() ),
				$dbr->expr( 'request_status', '!=', MirahezeRequestsStatus::Unconfirmed->value ),
			] )
			->orderBy( 'request_id', SelectQueryBuilder::SORT_DESC )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$requests = [];
		foreach ( $res as $row ) {
			$requests[] = [ 'id' => (int)$row->request_id, 'status' => $row->request_status ];
		}

		return $requests;
	}

	/** Deletes expired unconfirmed requests and old IP addresses. */
	public function purgeExpiredData(): void {
		// also runs on page views
		if ( $this->readOnlyMode->isReadOnly() ) {
			return;
		}

		$dbw = $this->dbService->getDbw();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'request_status' => MirahezeRequestsStatus::Unconfirmed->value ] )
			->andWhere( $dbw->expr( 'request_timestamp', '<', $this->getConfirmationCutoff( $dbw ) ) )
			->caller( __METHOD__ )
			->execute();

		$retentionDays = (int)$this->config->get( ConfigNames::IPRetentionDays );
		if ( $retentionDays <= 0 ) {
			return;
		}

		$cutoff = $dbw->timestamp( ConvertibleTimestamp::time() - $retentionDays * 86400 );

		// Open requests keep their IP only for the retention period from
		// submission, or a request that is never handled would keep it forever.
		// Once handled, the period is counted from completion instead.
		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'request_ip' => '' ] )
			->where( [
				$dbw->expr( 'request_ip', '!=', '' ),
				$dbw->orExpr( [
					$dbw->expr( 'request_completed_timestamp', '<', $cutoff ),
					$dbw->andExpr( [
						$dbw->expr( 'request_completed_timestamp', '=', null ),
						$dbw->expr( 'request_timestamp', '<', $cutoff ),
					] ),
				] ),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * The requester's account email, for CC'ing the decision, if they
	 * opted in and have a confirmed email.
	 */
	public function getRequesterCcEmail(): ?string {
		if ( !$this->wantsCcEmail() ) {
			return null;
		}

		$requester = $this->getRequester();
		if ( $requester && $requester->isRegistered() && $requester->isEmailConfirmed() ) {
			return $requester->getEmail();
		}

		return null;
	}

	public function wantsCcEmail(): bool {
		return (bool)$this->row->request_ccemail;
	}

	public function getIpBlocks(): array {
		if ( $this->getIp() === '' ) {
			return [];
		}

		$block = $this->blockManager->getIpBlock( $this->getIp(), true );
		if ( !$block ) {
			return [];
		}

		$blocks = $block instanceof CompositeBlock ? $block->toArray() : [ $block ];

		$out = [];
		foreach ( $blocks as $b ) {
			$out[] = $b->getTargetName() . ': ' . $b->getReasonComment()->text;
		}

		return $out;
	}

	public function getUsername(): string {
		return $this->row->request_username;
	}

	/**
	 * @return string[] HTML of each username check the name failed at submission
	 */
	public function getNameWarnings(): array {
		// keep requests viewable even with broken JSON
		$warnings = json_decode( $this->row->request_name_warnings ?? '[]', true );
		return is_array( $warnings ) ? $warnings : [];
	}

	public function getEmail(): string {
		return $this->row->request_email;
	}

	public function getReason(): string {
		return $this->row->request_reason;
	}

	public function getGlobalBlockReason(): ?string {
		return $this->row->request_globalblock_reason;
	}

	public function getExplanation(): string {
		return $this->row->request_explanation;
	}

	public function getComments(): string {
		return $this->row->request_comments;
	}

	/**
	 * @return string Empty once purged
	 */
	public function getIp(): string {
		return $this->row->request_ip;
	}

	public function getLanguage(): string {
		return $this->row->request_language;
	}

	public function getCompletedTimestamp(): ?string {
		return $this->row->request_completed_timestamp;
	}

	public function getCompletedUser(): ?User {
		if ( !$this->row->request_completed_actor ) {
			return null;
		}

		return $this->userFactory->newFromActorId( $this->row->request_completed_actor );
	}

	public function getNotes(): string {
		return $this->row->request_notes ?? '';
	}

	public function userExists(): bool {
		$user = $this->userFactory->newFromName( $this->getUsername() );
		return $user !== null && $user->isRegistered();
	}

	/** Whether handlers can see the request; unconfirmed ones are not in the queue yet. */
	public function isInQueue(): bool {
		return $this->exists() && $this->getStatus() !== MirahezeRequestsStatus::Unconfirmed->value;
	}

	/** Whether handling is finished or another handler is currently processing the request. */
	public function isFinalized(): bool {
		return in_array( $this->getStatus(), [
			MirahezeRequestsStatus::Complete->value,
			MirahezeRequestsStatus::Declined->value,
			MirahezeRequestsStatus::Starting->value,
			MirahezeRequestsStatus::Sending->value,
		], true );
	}

	private function getConfirmationCutoff( IReadableDatabase $db ): string {
		return $db->timestamp(
			ConvertibleTimestamp::time() - (int)$this->config->get( ConfigNames::AccountConfirmationExpiry )
		);
	}

	private static function newToken(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/** Only hashes are stored, so a dump can't rebuild links. */
	private static function hashToken( string $token ): string {
		return hash( 'sha256', $token );
	}
}
