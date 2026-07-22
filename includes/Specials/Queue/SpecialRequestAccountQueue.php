<?php

namespace Miraheze\MirahezeRequests\Specials\Queue;

use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Miraheze\MirahezeRequests\Specials\Pager\RequestAccountQueuePager;
use Miraheze\MirahezeRequests\Specials\Viewer\RequestAccountViewer;
use Miraheze\MirahezeRequests\Specials\Viewer\RequestViewer;

class SpecialRequestAccountQueue extends RequestQueue {
	private RequestAccountManager $requestManager;

	public function __construct(
		MirahezeRequestsDatabaseService $dbService,
		RequestAccountManager $requestManager,
		UserFactory $userFactory
	) {
		parent::__construct(
			'RequestAccountQueue',
			'handle-requestaccount',
			$dbService,
			$userFactory
		);
		$this->requestManager = $requestManager;
	}

	protected function makeViewer(): RequestViewer {
		return new RequestAccountViewer(
			$this->getConfig(),
			$this->getContext(),
			$this->requestManager
		);
	}

	protected function getFiltersFromRequest(): array {
		$req = $this->getRequest();
		return [
			'requester' => $req->getText( 'requester' ),
			'status' => $req->getText( 'status' ),
			'username' => $req->getText( 'username' ),
			'email' => $req->getText( 'email' ),
		];
	}

	protected function buildFilterFormDescriptor( array $filters ): array {
		return [
			'info' => [
				'type' => 'info',
				'default' => $this->msg( 'requestaccountqueue' )->text(),
			],
			'username' => [
				'type' => 'text',
				'name' => 'username',
				'label-message' => 'requestaccount-username-short',
				'default' => $filters['username'],
			],
			'email' => [
				'type' => 'text',
				'name' => 'email',
				'label-message' => 'requestaccount-email-short',
				'default' => $filters['email'],
			],
			'requester' => [
				'type' => 'user',
				'name' => 'requester',
				'label-message' => 'mirahezerequests-label-requester',
				'exist' => true,
				'default' => $filters['requester'],
			],
			'status' => [
				'type' => 'select',
				'name' => 'status',
				'label-message' => 'status',
				'options' => self::HTMLFORMOPTIONS,
				'default' => $filters['status'] ?: self::STATUS_PENDING,
			],
		];
	}

	protected function buildPager( array $filters ): RequestAccountQueuePager {
		return new RequestAccountQueuePager(
			$this->getContext(),
			$this->dbService,
			$this->getLinkRenderer(),
			$this->userFactory,
			$filters['requester'],
			$filters['status'],
			$filters['username'],
			$filters['email']
		);
	}
}
