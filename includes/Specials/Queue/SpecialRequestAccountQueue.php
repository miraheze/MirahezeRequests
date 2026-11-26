<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Specials\Queue;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\AccountCreator;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Miraheze\MirahezeRequests\Specials\AccountRequestRouter;
use Miraheze\MirahezeRequests\Specials\Pager\RequestAccountQueuePager;
use Miraheze\MirahezeRequests\Specials\Viewer\RequestAccountViewer;
use Miraheze\MirahezeRequests\Specials\Viewer\RequestViewer;

class SpecialRequestAccountQueue extends RequestQueue {

	public function __construct(
		MirahezeRequestsDatabaseService $dbService,
		UserFactory $userFactory,
		private readonly AccountRequestRouter $accountRequestRouter,
		private readonly AuthManager $authManager,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly FormatterFactory $formatterFactory,
		private readonly AccountCreator $accountCreator,
		private readonly AccountRequestMailer $requestMailer,
		private readonly RequestAccountManager $requestManager,
	) {
		parent::__construct(
			'RequestAccountQueue',
			'handle-requestaccount',
			$dbService,
			$userFactory
		);
	}

	/** @inheritDoc */
	protected function makeViewer(): RequestViewer {
		return new RequestAccountViewer(
			$this->getContext(),
			$this->getLinkRenderer(),
			$this->authManager,
			$this->extensionRegistry,
			$this->formatterFactory->getStatusFormatter( $this->getContext() ),
			$this->accountCreator,
			$this->requestMailer,
			$this->requestManager,
			$this->dbService,
			$this->accountRequestRouter,
		);
	}

	/** @inheritDoc */
	protected function lookupRequest( int $requestId ): void {
		if ( !$this->accountRequestRouter->ensureAuthenticationDomain( $this, (string)$requestId ) ) {
			return;
		}

		parent::lookupRequest( $requestId );
	}

	/** @inheritDoc */
	protected function showFilterAndPager(): void {
		$request = $this->getRequest();
		$hasLegacyEmailFilter = !$request->wasPosted() && $request->getCheck( 'email' );
		if ( !$hasLegacyEmailFilter && !$this->accountRequestRouter->ensureQueueDomain( $this ) ) {
			return;
		}

		if ( $request->wasPosted() || $hasLegacyEmailFilter ) {
			// Search and paging URLs must keep the filter without carrying the address itself.
			$query = $request->getValues(
				'type', 'username', 'requester', 'status', 'sort', 'asc', 'desc', 'offset', 'dir', 'limit', 'uselang'
			);
			$email = $request->getText( 'email' );
			if ( $email !== '' ) {
				$query['emailRequestId'] = $this->requestManager->findQueuedRequestIdByEmail( $email ) ?? 0;
			}
			$this->getOutput()->redirect( $this->accountRequestRouter->getQueueURL( $query ) );
			return;
		}

		// handlers view the queue regularly, so expired data gets purged
		DeferredUpdates::addCallableUpdate( function () {
			$this->requestManager->purgeExpiredData();
		} );

		parent::showFilterAndPager();
	}

	/** @inheritDoc */
	protected function getFiltersFromRequest(): array {
		$req = $this->getRequest();
		$status = $req->getText( 'status' );

		return [
			'requester' => $req->getText( 'requester' ),
			'status' => in_array( $status, $this->getStatusesForCurrentTab(), true ) ? $status : '',
			'username' => $req->getText( 'username' ),
			'email' => $this->getEmailFilter(),
		];
	}

	/** @return ?string Empty for no filter, null if the requested filter has no matching record. */
	private function getEmailFilter(): ?string {
		$request = $this->getRequest();
		if ( !$request->getCheck( 'emailRequestId' ) ) {
			return '';
		}

		$this->requestManager->getById( $request->getInt( 'emailRequestId' ) );
		return $this->requestManager->isInQueue() ? $this->requestManager->getEmail() : null;
	}

	/** @return string[] */
	private function getStatusesForCurrentTab(): array {
		return match ( $this->getType() ) {
			'open' => MirahezeRequestsStatus::OPEN,
			'closed' => MirahezeRequestsStatus::CLOSED,
			default => array_map(
				static fn ( MirahezeRequestsStatus $case ): string => $case->value,
				MirahezeRequestsStatus::queueCases()
			),
		};
	}

	/** @inheritDoc */
	protected function buildFilterFormDescriptor( array $filters ): array {
		// Message-based so option labels are actually translatable,
		// rather than a hardcoded English string per status.
		$statusOptions = [ 'mirahezerequests-status-all' => '*' ];
		foreach ( $this->getStatusesForCurrentTab() as $status ) {
			$case = MirahezeRequestsStatus::from( $status );
			$statusOptions[$case->getMessageKey()] = $case->value;
		}

		return [
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
				'default' => $filters['email'] ?? '',
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
				'label-message' => 'mirahezerequests-label-status',
				'options-messages' => $statusOptions,
				'default' => $filters['status'] ?: '*',
			],
		];
	}

	/** @inheritDoc */
	protected function buildPager( array $filters, string $type ): RequestAccountQueuePager {
		return new RequestAccountQueuePager(
			$this->getContext(),
			$this->dbService,
			$this->getLinkRenderer(),
			$this->userFactory,
			$filters['requester'],
			$filters['status'],
			$type,
			$filters['username'],
			$filters['email'],
			$this->requestManager->canSeeIp( $this->getUser() )
		);
	}
}
