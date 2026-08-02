<?php

namespace Miraheze\MirahezeRequests\Jobs;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\JobQueue\Job;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;

class CreateAccountJob extends Job implements MirahezeRequestsStatus {

	public const string JOB_NAME = 'MirahezeRequestsCreateAccountJob';
	private readonly string $username;
	private readonly string $email;

	public function __construct(
		array $params,
		private readonly UserFactory $userFactory,
		private readonly AuthManager $authManager,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->username = $params['username'];
		$this->email = $params['email'];
	}

	public function run(): bool {
		$user = $this->userFactory->newFromName( $this->username );

		if ( !$user->isRegistered() ) {
			$user->setEmail( $this->email );

			// TODO: logging
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

			$status = $user->sendMail( $subjectMessage->text(), $bodyMessage->text() );

			return true;
		}
		return false;
	}

	public function allowRetries(): false {
		return false;
	}
}
