<?php

namespace Miraheze\MirahezeRequests\Jobs;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\JobQueue\Job;
use MediaWiki\Language\FormatterFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Mail\MailAddress;
use MediaWiki\Mail\UserMailer;
use MediaWiki\MainConfigNames;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsConstants;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;

class CreateAccountJob extends Job {

	public const string JOB_NAME = 'MirahezeRequestsCreateAccountJob';

	private readonly int $id;
	private readonly string $performerName;

	public function __construct(
		array $params,
		private readonly UserFactory $userFactory,
		private readonly AuthManager $authManager,
		private readonly RequestAccountManager $requestManager,
		private readonly Config $mainConfig,
		private readonly FormatterFactory $formatterFactory,
	) {
		parent::__construct( self::JOB_NAME, $params );
		$this->id = $params['id'];
		$this->performerName = $params['performer'];
	}

	public function run(): bool {
		$this->requestManager->getById( $this->id );

		$sysUser = User::newSystemUser( MirahezeRequestsConstants::SYSTEM_USER, [ 'steal' => true ] );
		$performer = $this->userFactory->newFromName( $this->performerName ) ?: $sysUser;

		$username = $this->requestManager->getUsername();
		$email = $this->requestManager->getEmail();

		$user = $this->userFactory->newFromName( $username );

		if ( !$user ) {
			$this->requestManager->resolve(
				MirahezeRequestsStatus::Failed->value, $performer,
				wfMessage( 'requestaccount-notes-invalid-username' )->text()
			);
			return false;
		}

		if ( $user->isRegistered() ) {
			// The account already exists, the desired end state is
			// already satisfied; nothing further to do.
			$this->requestManager->resolve(
				MirahezeRequestsStatus::Complete->value, $performer,
				wfMessage( 'requestaccount-notes-already-exists' )->text()
			);
			return true;
		}

		$user->setEmail( $email );

		$status = $user->addToDatabase();
		if ( !$status->isGood() ) {
			$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
			$this->requestManager->resolve(
				MirahezeRequestsStatus::Failed->value, $performer,
				wfMessage(
					'requestaccount-notes-creation-failed',
					$statusFormatter->getMessage( $status )->text()
				)->text()
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
				MirahezeRequestsStatus::Failed->value, $performer,
				wfMessage( 'requestaccount-notes-password-failed' )->text()
			);
			return false;
		}

		$this->authManager->changeAuthenticationData( $req );

		$subjectMessage = wfMessage( 'requestaccount-created-email-title' );
		$bodyMessage = wfMessage( 'requestaccount-created-email-text', $username, $newTempPassword );

		// $wgPasswordSender, not the system user's own email (unset -
		// that broke delivery on some mail transports).
		$from = new MailAddress(
			$this->mainConfig->get( MainConfigNames::PasswordSender ),
			wfMessage( 'emailsender' )->inContentLanguage()->text()
		);

		// Plain-text mail body: not an XSS risk.
		// @phan-suppress-next-line SecurityCheck-XSS
		$mailStatus = UserMailer::send(
			new MailAddress( $email, $username ),
			$from,
			$subjectMessage->text(),
			$bodyMessage->text()
		);

		$ccEmail = $this->requestManager->getRequesterCcEmail();
		if ( $ccEmail && $ccEmail !== $email ) {
			// @phan-suppress-next-line SecurityCheck-XSS
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
			$statusFormatter = $this->formatterFactory->getStatusFormatter( RequestContext::getMain() );
			$this->requestManager->resolve(
				MirahezeRequestsStatus::Complete->value, $performer,
				wfMessage(
					'requestaccount-notes-created-mail-failed',
					$statusFormatter->getMessage( $mailStatus )->text()
				)->text()
			);

			return true;
		}

		$this->requestManager->resolve(
			MirahezeRequestsStatus::Complete->value, $performer,
			wfMessage( 'requestaccount-notes-created' )->text()
		);

		return true;
	}

	public function allowRetries(): false {
		return false;
	}
}
