<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\RenameWikiRequestQueuePager;
use Miraheze\MirahezeRequests\RequestManager;
use Miraheze\MirahezeRequests\RequestViewer;
use Wikimedia\Rdbms\IConnectionProvider;

class SpecialRequestRenameWikiQueue extends SpecialPage
	implements MirahezeRequestsStatus {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly RequestManager $requestManager,
		private readonly UserFactory $userFactory
	) {
		parent::__construct( 'RequestRenameWikiQueue' );
	}

	/**
	 * @param ?string $par
	 * @throws ErrorPageError
	 */
	public function execute( $par ): void {
		$this->setHeaders();

		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-mirahezerequests' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			throw new ErrorPageError(
				'renamewiki-requestrenamewikiqueue-notcentral',
				'renamewiki-requestrenamewikiqueue-notcentral-text'
			);
		}

		if ( $par ) {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
			$this->lookupRequest( $par );
			return;
		}

		$this->doPagerStuff();
	}

	private function doPagerStuff(): void {
		$requester = $this->getRequest()->getText( 'requester' );
		$status = $this->getRequest()->getText( 'status' );
		$oldwiki = $this->getRequest()->getText( 'oldwiki' );
		$newwiki = $this->getRequest()->getText( 'newwiki' );

		$formDescriptor = [
			'info' => [
				'type' => 'info',
				'default' => $this->msg( 'requestrenamewikiqueue-header-info' )->text(),
			],
			'oldwiki' => [
				'type' => 'text',
				'name' => 'oldwiki',
				'label-message' => 'renamewiki-label-oldwiki',
				'default' => $oldwiki,
			],
			'newwiki' => [
				'type' => 'text',
				'name' => 'newwiki',
				'label-message' => 'renamewiki-label-newwiki',
				'default' => $newwiki,
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'renamewiki-label-requester',
				'exist' => true,
				'default' => $requester,
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'renamewiki-label-status',
				'options-messages' => [
					'renamewiki-label-pending' => self::STATUS_PENDING,
					'renamewiki-label-starting' => self::STATUS_STARTING,
					'renamewiki-label-inprogress' => self::STATUS_INPROGRESS,
					'renamewiki-label-complete' => self::STATUS_COMPLETE,
					'renamewiki-label-declined' => self::STATUS_DECLINED,
					'renamewiki-label-failed' => self::STATUS_FAILED,
					'renamewiki-label-all' => '*',
				],
				'default' => $status ?: self::STATUS_PENDING,
			],
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setWrapperLegendMsg( 'requestrenamewikiqueue-header' )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );

		$pager = new RenameWikiRequestQueuePager(
			$this->getContext(),
			$this->connectionProvider,
			$this->getLinkRenderer(),
			$this->userFactory,
			$requester,
			$status,
			$newwiki,
			$oldwiki
		);

		$table = $pager->getFullOutput();
		$this->getOutput()->addParserOutputContent( $table );
	}

	private function lookupRequest( string $par ): void {
		$requestViewer = new RequestViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->requestManager
		);

		$htmlForm = $requestViewer->getForm( (int)$par );
		if ( $htmlForm ) {
			$htmlForm->show();
		}
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'other';
	}
}
