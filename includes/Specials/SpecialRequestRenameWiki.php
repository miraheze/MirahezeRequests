<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\PermissionsError;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Html\Html;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\ManageWiki\Helpers\Factories\ModuleFactory;
use Miraheze\MirahezeRequests\ConfigNames;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsValidator;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

class SpecialRequestRenameWiki extends FormSpecialPage
	implements MirahezeRequestsStatus {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly MirahezeRequestsValidator $validator,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly UserFactory $userFactory,
		private readonly ?ModuleFactory $moduleFactory
	) {
		parent::__construct( 'RequestRenameWiki', 'request-renamewiki' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->requireLogin( 'renamewiki-notloggedin' );
		$this->setParameter( $par );
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-mirahezerequests' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'renamewiki-notcentral', 'renamewiki-notcentral-text' );
		}

		$this->checkPermissions();

		if ( $this->getConfig()->get( ConfigNames::HelpUrl ) ) {
			$this->getOutput()->addHelpLink( $this->getConfig()->get( ConfigNames::HelpUrl ), true );
		}

		$form = $this->getForm();
		if ( $form->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		return [
			'oldwiki' => [
				'type' => 'text',
				'label-message' => 'renamewiki-label-oldwiki',
				'help-message' => 'renamewiki-help-oldwiki',
				'required' => true,
				'validation-callback' => [ $this->validator, 'isValidDatabase' ],
			],
			'newwiki' => [
				'type' => 'text',
				'label-message' => 'renamewiki-label-newwiki',
				'help-message' => 'renamewiki-help-newwiki',
				'required' => true,
				'validation-callback' => [ $this->validator, 'isInvalidDatabase' ],
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 6,
				'label-message' => 'renamewiki-label-reason',
				'help-message' => 'renamewiki-help-reason',
				'required' => true,
				'validation-callback' => [ $this->validator, 'isValidReason' ],
			],
		];
	}

	/**
	 * @inheritDoc
	 * @throws PermissionsError
	 */
	public function onSubmit( array $data ): Status {
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();
		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		if (
			$this->getUser()->pingLimiter( 'request-renamewiki' )
		) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-mirahezerequests' );
		$duplicate = $dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( 'renamewiki_requests' )
			->where( [
				'request_reason' => $data['reason'],
				'request_status' => self::STATUS_PENDING,
			] )
			->caller( __METHOD__ )
			->fetchRow();

// if ( (bool)$duplicate ) {
//			return Status::newFatal( 'renamewiki-duplicate-request' );
//		}

		$timestamp = $dbw->timestamp();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'renamewiki_requests' )
			->ignore()
			->row( [
				'request_oldwiki' => $data['oldwiki'],
				'request_newwiki' => $data['newwiki'],
				'request_reason' => $data['reason'],
				'request_status' => self::STATUS_PENDING,
				'request_actor' => $this->getUser()->getActorId(),
				'request_timestamp' => $timestamp,
			] )
			->caller( __METHOD__ )
			->execute();

		$requestID = (string)$dbw->insertId();
		$requestQueueLink = SpecialPage::getTitleValueFor( 'RequestRenameWikiQueue', $requestID );

		$requestLink = $this->getLinkRenderer()->makeLink( $requestQueueLink, "#$requestID" );

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'renamewiki-success' )->rawParams( $requestLink )->escaped()
			)
		);

		$logEntry = new ManualLogEntry( $this->getLogType( $data['newwiki'] ), 'request' );

		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $requestQueueLink );
		$logEntry->setComment( $data['reason'] );

		$logEntry->setParameters(
			[
				'4::requestTarget' => $data['newwiki'],
				'5::requestLink' => Message::rawParam( $requestLink ),
			]
		);

		$logID = $logEntry->insert( $dbw );
		$logEntry->publish( $logID );

		if (
			$this->extensionRegistry->isLoaded( 'Echo' ) &&
			$this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests )
		) {
			$this->sendNotifications( $data['reason'], $this->getUser()->getName(), $requestID, $data['newwiki'] );
		}

		return Status::newGood();
	}

	public function getLogType( string $newwiki ): string {
		if (
			!$this->extensionRegistry->isLoaded( 'ManageWiki' ) ||
			!$this->moduleFactory ||
			!$this->moduleFactory->isEnabled( 'core' )
		) {
			return 'renamewiki';
		}

		$mwCore = $this->moduleFactory->core( $newwiki );
		if ( !$mwCore->isEnabled( 'private-wikis' ) ) {
			return 'renamewiki';
		}

		return $mwCore->isPrivate() ? 'renamewikiprivate' : 'renamewiki';
	}

	public function sendNotifications(
		string $reason,
		string $requester,
		string $requestID,
		string $newwiki
	): void {
		$notifiedUsers = array_filter(
			array_map(
				fn ( string $userName ): ?User => $this->userFactory->newFromName( $userName ),
				$this->getConfig()->get( ConfigNames::UsersNotifiedOnAllRequests )
			)
		);

		$requestLink = SpecialPage::getTitleFor( 'RequestRenameWikiQueue', $requestID )->getFullURL();
		foreach ( $notifiedUsers as $receiver ) {
			if (
				!$receiver->isAllowed( 'handle-renamewiki-requests' ) ||
				(
					$this->getLogType( $newwiki ) === 'renamewikiprivate' &&
					!$receiver->isAllowed( 'view-private-renamewiki-requests' )
				)
			) {
				continue;
			}

			Event::create( [
				'type' => 'renamewiki-new-request',
				'extra' => [
					'request-id' => $requestID,
					'request-oldwiki' => $oldwiki,
					'reason' => $reason,
					'requester' => $requester,
					'newwiki' => $newwiki,
					'notifyAgent' => true,
				],
				'agent' => $receiver,
			] );
		}
	}

	/** @throws ErrorPageError|PermissionsError */
	public function checkPermissions(): void {
		parent::checkPermissions();

		if ( !$this->getAuthority()->isDefinitelyAllowed( 'request-renamewiki' ) ) {
			throw new PermissionsError( 'request-renamewiki' );
		}

		$this->checkReadOnly();
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'other';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}
}
