<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\FileRepo\RepoGroup;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\ActorStoreFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserGroupManagerFactory;
use MessageLocalizer;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\MirahezeRequests\Jobs\RenameWikiJob;
use stdClass;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\Platform\ISQLPlatform;
use Wikimedia\Rdbms\SelectQueryBuilder;

class RequestManager {

	private const SYSTEM_USERS = [
		'RenameWiki Extension',
		'RenameWiki Status Update',
		'RequestRenameWiki Extension',
		'RequestRenameWiki Status Update',
	];

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::ScriptCommand,
	];

	private IDatabase $dbw;
	private stdClass|false $row;
	private int $ID;

	public function __construct(
		private readonly ActorStoreFactory $actorStoreFactory,
		private readonly IConnectionProvider $connectionProvider,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly LinkRenderer $linkRenderer,
		private readonly RepoGroup $repoGroup,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
		private readonly UserFactory $userFactory,
		private readonly UserGroupManagerFactory $userGroupManagerFactory,
		private readonly ?ModuleFactory $moduleFactory
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function loadFromID( int $requestID ): void {
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-mirahezerequests' );
		$this->ID = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'renamewiki_requests' )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	public function exists(): bool {
		return (bool)$this->row;
	}

	public function addComment( string $comment, User $user ): void {
		$this->dbw->newInsertQueryBuilder()
			->insertInto( 'renamewiki_request_comments' )
			->row( [
				'request_id' => $this->ID,
				'request_comment_text' => $comment,
				'request_comment_timestamp' => $this->dbw->timestamp(),
				'request_comment_actor' => $user->getActorId(),
			] )
			->caller( __METHOD__ )
			->execute();

		if (
			$this->extensionRegistry->isLoaded( 'Echo' ) &&
			!in_array( $user->getName(), self::SYSTEM_USERS, true )
		) {
			$this->sendNotification( $comment, 'renamewiki-request-comment', $user );
		}
	}

	public function logStatusUpdate( string $comment, string $newStatus, User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestRenameWikiQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'renamewikiprivate' : 'renamewiki',
			'statusupdate'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		if ( $comment ) {
			$logEntry->setComment( $comment );
		}

		$logEntry->setParameters(
			[
				'4::requestLink' => Message::rawParam( $requestLink ),
				'5::requestStatus' => mb_strtolower( $this->messageLocalizer->msg(
					"renamewiki-label-$newStatus"
				)->inContentLanguage()->text() ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function logStarted( User $user ): void {
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestRenameWikiQueue', (string)$this->ID );
		$requestLink = $this->linkRenderer->makeLink( $requestQueueLink, "#{$this->ID}" );

		$logEntry = new ManualLogEntry(
			$this->isPrivate( forced: false ) ? 'renamewikiprivate' : 'renamewiki',
			'started'
		);

		$logEntry->setPerformer( $user );
		$logEntry->setTarget( $requestQueueLink );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $this->getTarget(),
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $this->dbw );
		$logEntry->publish( $logID );
	}

	public function sendNotification( string $comment, string $type, User $user ): void {
		$requestLink = SpecialPage::getTitleFor( 'RequestRenameWikiQueue', (string)$this->ID )->getFullURL();
		$involvedUsers = array_values( array_filter(
			array_diff( $this->getInvolvedUsers(), [ $user ] )
		) );

		foreach ( $involvedUsers as $receiver ) {
			Event::create( [
				'type' => $type,
				'extra' => [
					'request-id' => $this->ID,
					'request-url' => $requestLink,
					'comment' => $comment,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	public function getComments(): array {
		$res = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'renamewiki_request_comments' )
			->where( [ 'request_id' => $this->ID ] )
			->orderBy( 'request_comment_timestamp', SelectQueryBuilder::SORT_ASC )
			->caller( __METHOD__ )
			->fetchResultSet();

		if ( !$res->numRows() ) {
			return [];
		}

		$comments = [];
		foreach ( $res as $row ) {
			$user = $this->userFactory->newFromActorId( $row->request_comment_actor );
			$comments[] = [
				'comment' => $row->request_comment_text,
				'timestamp' => $row->request_comment_timestamp,
				'user' => $user,
			];
		}

		return $comments;
	}

	public function getInvolvedUsers(): array {
		return array_unique( array_merge( array_column( $this->getComments(), 'user' ), [ $this->getRequester() ] ) );
	}

	public function getCommand(): string {
		$command = $this->options->get( ConfigNames::ScriptCommand );

		return str_replace( [
			'{IP}',
			'{oldwiki}',
			'{newwiki}',
		], [
			MW_INSTALL_PATH,
			$this->getOldWiki(),
			$this->getNewWiki(),
		], $command );
	}

	/** @return string[] */
	public function getUserGroupsFromTarget(): array {
		$userName = $this->getRequester()->getName();
		$remoteUser = $this->actorStoreFactory
			->getUserIdentityLookup( $this->getTarget() )
			->getUserIdentityByName( $userName );

		if ( !$remoteUser ) {
			return [ $this->messageLocalizer->msg( 'renamewiki-usergroups-none' )->text() ];
		}

		return $this->userGroupManagerFactory
			->getUserGroupManager( $this->getTarget() )
			->getUserGroups( $remoteUser );
	}

	public function getReason(): string {
		return $this->row->request_reason;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromActorId( $this->row->request_actor );
	}

	public function getStatus(): string {
		return $this->row->request_status;
	}

	public function getOldWiki(): string {
		return $this->row->request_oldwiki;
	}

	public function getNewWiki(): string {
		return $this->row->request_newwiki;
	}

	public function getTimestamp(): string {
		return $this->row->request_timestamp;
	}

	public function isLocked(): bool {
		return (bool)$this->row->request_locked;
	}

	public function isPrivate( bool $forced ): bool {
		if ( !$forced && $this->row->request_private ) {
			return true;
		}

		if (
			!$this->extensionRegistry->isLoaded( 'ManageWiki' ) ||
			!$this->moduleFactory ||
			!$this->moduleFactory->isEnabled( 'core' )
		) {
			return false;
		}

		$mwCore = $this->moduleFactory->core( $this->getTarget() );
		if ( !$mwCore->isEnabled( 'private-wikis' ) ) {
			return false;
		}

		return $mwCore->isPrivate();
	}

	public function startAtomic( string $fname ): void {
		$this->dbw->startAtomic( $fname );
	}

	public function setLocked( int $locked ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'renamewiki_requests' )
			->set( [ 'request_locked' => $locked ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setPrivate( int $private ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'renamewiki_requests' )
			->set( [ 'request_private' => $private ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setReason( string $reason ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'renamewiki_requests' )
			->set( [ 'request_reason' => $reason ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setOldWiki( string $oldwiki ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'renamewiki_requests' )
			->set( [ 'request_oldwiki' => $oldwiki ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setStatus( string $status ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'renamewiki_requests' )
			->set( [ 'request_status' => $status ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function setNewWiki( string $newwiki ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( 'renamewiki_requests' )
			->set( [ 'request_newwiki' => $newwiki ] )
			->where( [ 'request_id' => $this->ID ] )
			->caller( __METHOD__ )
			->execute();
	}

	public function executeJob( string $username ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup( $this->getTarget() )->push(
			new JobSpecification(
				RenameWikiJob::JOB_NAME,
				[
					'requestid' => $this->ID,
					'username' => $username,
				]
			)
		);
	}

	public function endAtomic( string $fname ): void {
		$this->dbw->endAtomic( $fname );
	}
}
