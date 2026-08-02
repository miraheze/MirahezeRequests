<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Block\BlockManager;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\Jobs\CreateAccountJob;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class RequestAccountManager extends RequestManager {

	public function __construct(
		private readonly JobQueueGroupFactory $jobQueueGroupFactory,
		private readonly MirahezeRequestsDatabaseService $dbService,
		private readonly UserFactory $userFactory,
		private readonly BlockManager $blockManager,
	) {
		parent::__construct(
			'account',
			$this->dbService,
			$this->userFactory,
		);
	}

	public function executeJob( string $username, string $email ): void {
		$this->jobQueueGroupFactory->makeJobQueueGroup()->push(
			new JobSpecification(
				CreateAccountJob::JOB_NAME,
				[
					'username' => $username,
					'email'    => $email,
				]
			)
		);
	}

	public function getIpBlocks(): false|array {
		$user = $this->getRequester();
		$out = [];
		$blocks = $this->blockManager->getIpBlock( $user->getName(), true );

		if ( $blocks instanceof CompositeBlock ) {
			foreach ( $blocks->toArray() as $block ) {
				$out[] = [ $block->getTarget(), $block->getTargetComment()->text ];
			}
			return $out;
		}
		if ( $blocks ) {
			return [ $blocks->getTarget(), $blocks->getReasonComment()->text ];
		}
		 return false;
	}

	public function getUsername() {
		return $this->row->request_username;
	}

	public function getEmail() {
		return $this->row->request_email;
	}

	public function getReason() {
		return $this->row->request_reason;
	}

	public function getExplanation() {
		return $this->row->request_explanation;
	}

	public function userExists(): bool {
		$user = $this->userFactory->newFromName( $this->getUsername() );

		if ( $user->isRegistered() ) {
			return true;
		}
		return false;
	}

	public function invalidStatus(): bool {
		if ( $this->getStatus() === MirahezeRequestsStatus::STATUS_COMPLETE ) {
			return true;
		}
		return false;
	}
}
