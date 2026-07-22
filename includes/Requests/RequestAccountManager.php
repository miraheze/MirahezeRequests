<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Block\BlockManager;
use MediaWiki\Block\CompositeBlock;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\User\User;
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
					'email' => $email,
					'ccEmail' => $this->getRequesterCcEmail(),
				]
			)
		);
	}

	public function sendDeclineEmail( string $reason ): void {
		$sysUser = User::newSystemUser( 'MirahezeRequests', [ 'steal' => true ] );
		$from = MailAddress::newFromUser( $sysUser );

		$subjectMessage = wfMessage( 'requestaccount-declined-email-title' );
		$bodyMessage = wfMessage( 'requestaccount-declined-email-text', $reason );

		UserMailer::send(
			new MailAddress( $this->getEmail(), $this->getUsername() ),
			$from,
			$subjectMessage->text(),
			$bodyMessage->text()
		);

		$ccEmail = $this->getRequesterCcEmail();
		if ( $ccEmail && $ccEmail !== $this->getEmail() ) {
			UserMailer::send(
				new MailAddress( $ccEmail ),
				$from,
				$subjectMessage->text(),
				$bodyMessage->text()
			);
		}
	}

	/**
	 * The requester's own account email, to CC on the accept/decline
	 * notification, if they opted in when submitting the request and
	 * are a logged-in user with a confirmed email address.
	 */
	public function getRequesterCcEmail(): ?string {
		if ( !$this->wantsCcEmail() ) {
			return null;
		}

		$requester = $this->getRequester();
		if ( $requester->isRegistered() && $requester->isEmailConfirmed() ) {
			return $requester->getEmail();
		}

		return null;
	}

	public function wantsCcEmail(): bool {
		return (bool)$this->row->request_ccemail;
	}

	public function getIpBlocks(): array {
		$user = $this->getRequester();
		$block = $this->blockManager->getIpBlock( $user->getName(), true );

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

	public function getComments() {
		return $this->row->request_comments;
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
