<?php

namespace Miraheze\MirahezeRequests\Jobs;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\Config\Config;
use MediaWiki\JobQueue\Job;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;

class CreateAccountJob extends Job implements MirahezeRequestsStatus {

	public const string JOB_NAME = 'MirahezeRequestsCreateAccountJob';

	private readonly int $id;
	private readonly string $performerName;

	public function __construct(
		array $params,
		private readonly UserFactory $userFactory,
		private readonly AuthManager $authManager,
		private readonly RequestAccountManager $requestManager,
		private readonly Config $mainConfig,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->id = $params['id'];
		$this->performerName = $params['performer'];
	}

	public function run(): bool {
		$this->requestManager->getById( $this->id );

		$sysUser = User::newSystemUser( 'MirahezeRequests', [ 'steal' => true ] );
		$performer = $this->userFactory->newFromName( $this->performerName ) ?: $sysUser;

		$username = $this->requestManager->getUsername();
		$email = $this->requestManager->getEmail();

		$user = $this->userFactory->newFromName( $username );

		if ( !$user ) {
			$this->requestManager->resolve(
				self::STATUS_FAILED, $performer,
				wfMessage( 'requestaccount-notes-invalid-username' )->text()
			);
			return false;
		}

		if ( $user->isRegistered() ) {
			// The account already exists, the desired end state is
			// already satisfied; nothing further to do.
			$this->requestManager->resolve(
				self::STATUS_COMPLETE, $performer,
				wfMessage( 'requestaccount-notes-already-exists' )->text()
			);
			return true;
		}

		$user->setEmail( $email );

		$status = $user->addToDatabase();
		if ( !$status->isGood() ) {
			$this->requestManager->resolve(
				self::STATUS_FAILED, $performer,
				wfMessage( 'requestaccount-notes-creation-failed', $status->getWikiText() )->text()
			);
			return false;
		}

		$req = TemporaryPasswordAuthenticationRequest::newRandom();
		$newTempPassword = $req->password;

		$req->action = AuthManager::ACTION_CHANGE;
		$req->username = $username;
		$req->mailpassword = false; // send our own custom email
		$req->caller = $sysUser->getName();

		$status = $this->authManager->allowsAuthenticationDataChange( $req, false );
		if ( !$status->isGood() ) {
			$this->requestManager->resolve(
				self::STATUS_FAILED, $performer,
				wfMessage( 'requestaccount-notes-password-failed' )->text()
			);
			return false;
		}

		$this->authManager->changeAuthenticationData( $req );

		$subjectMessage = wfMessage( 'requestaccount-created-email-title' );
		$bodyMessage = wfMessage( 'requestaccount-created-email-text', $username, $newTempPassword );

		// Use the site's configured password-sender address, not the
		// MirahezeRequests system user's own email (which is unset,
		// since it's an auto-created account with no email configured -
		// that produced a blank/invalid From header and silently broke
		// delivery on some mail transports).
		$from = new MailAddress(
			$this->mainConfig->get( MainConfigNames::PasswordSender ),
			wfMessage( 'emailsender' )->inContentLanguage()->text()
		);

		$mailStatus = UserMailer::send(
			new MailAddress( $email, $username ),
			$from,
			$subjectMessage->text(),
			$bodyMessage->text()
		);

		$ccEmail = $this->requestManager->getRequesterCcEmail();
		if ( $ccEmail && $ccEmail !== $email ) {
			UserMailer::send(
				new MailAddress( $ccEmail ),
				$from,
				$subjectMessage->text(),
				$bodyMessage->text()
			);
		}

		$logEntry = new ManualLogEntry( 'newusers', 'byemail' );
		$logEntry->setPerformer( $performer );
		$logEntry->setTarget( $user->getUserPage() );
		$logEntry->setComment( '' );
		$logEntry->setParameters( [ '4::userid' => $user->getId() ] );
		$logEntry->publish( $logEntry->insert() );

		if ( !$mailStatus->isGood() ) {
			$this->requestManager->resolve(
				self::STATUS_COMPLETE, $performer,
				wfMessage(
					'requestaccount-notes-created-mail-failed',
					$mailStatus->getWikiText()
				)->text()
			);

			return true;
		}

		$this->requestManager->resolve(
			self::STATUS_COMPLETE, $performer,
			wfMessage( 'requestaccount-notes-created' )->text()
		);

		return true;
	}

	public function allowRetries(): false {
		return false;
	}
}
