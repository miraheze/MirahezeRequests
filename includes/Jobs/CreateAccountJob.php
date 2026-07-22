<?php

namespace Miraheze\MirahezeRequests\Jobs;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\JobQueue\Job;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;

class CreateAccountJob extends Job implements MirahezeRequestsStatus {

	public const string JOB_NAME = 'MirahezeRequestsCreateAccountJob';

	private readonly string $username;
	private readonly string $email;
	private readonly ?string $ccEmail;

	public function __construct(
		array $params,
		private readonly UserFactory $userFactory,
		private readonly AuthManager $authManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->username = $params['username'];
		$this->email = $params['email'];
		$this->ccEmail = $params['ccEmail'] ?? null;
	}

	public function run(): bool {
		$user = $this->userFactory->newFromName( $this->username );

		if ( !$user || $user->isRegistered() ) {
			return false;
		}

		$user->setEmail( $this->email );

		$status = $user->addToDatabase();
		if ( !$status->isGood() ) {
			return false;
		}

		$req = TemporaryPasswordAuthenticationRequest::newRandom();
		$newTempPassword = $req->password;
		$sysUser = User::newSystemUser( 'MirahezeRequests', [ 'steal' => true ] );

		$req->action = AuthManager::ACTION_CHANGE;
		$req->username = $this->username;
		$req->mailpassword = false; // send our own custom email
		$req->caller = $sysUser->getName();

		$status = $this->authManager->allowsAuthenticationDataChange( $req, false );
		if ( !$status->isGood() ) {
			return false;
		}

		$this->authManager->changeAuthenticationData( $req );

		$subjectMessage = wfMessage( 'requestaccount-created-email-title' );
		$bodyMessage = wfMessage( 'requestaccount-created-email-text', $this->username, $newTempPassword );

		$from = MailAddress::newFromUser( $sysUser );
		UserMailer::send(
			new MailAddress( $this->email, $this->username ),
			$from,
			$subjectMessage->text(),
			$bodyMessage->text()
		);

		if ( $this->ccEmail && $this->ccEmail !== $this->email ) {
			UserMailer::send(
				new MailAddress( $this->ccEmail ),
				$from,
				$subjectMessage->text(),
				$bodyMessage->text()
			);
		}

		return true;
	}

	public function allowRetries(): false {
		return false;
	}
}
