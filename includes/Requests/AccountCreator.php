<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\CreationReasonAuthenticationRequest;
use MediaWiki\Auth\TemporaryPasswordAuthenticationRequest;
use MediaWiki\Auth\UsernameAuthenticationRequest;
use MediaWiki\Extension\AntiSpoof\AntiSpoofAuthenticationRequest;
use MediaWiki\Extension\TitleBlacklist\TitleBlacklistAuthenticationRequest;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IDBAccessObject;

/** Creates accounts through AuthManager, so CentralAuth makes them global. */
class AccountCreator {

	/** SUL3 rejects creations on non-login domains; this forces SUL2 (T390751). */
	public const FULL_PROVIDERS_QUERY = [ 'usesul3' => '0' ];

	private bool $creating = false;

	public function __construct(
		private readonly AuthManager $authManager,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly UserFactory $userFactory,
	) {
	}

	/** Whether a request's account is being created, so hooks skip checks the requester passed. */
	public function isCreating(): bool {
		return $this->creating;
	}

	/**
	 * @param int $requestId
	 * @param string $name
	 * @param string $email Already confirmed by the requester
	 * @param User $handler
	 * @param bool $ignoreTitleBlacklist Only works with the right
	 * @param bool $ignoreAntiSpoof Only works with the right
	 * @param WebRequest $webRequest The handler's request, holding the AuthManager session
	 * @return Status With the temporary password, which the caller must send
	 */
	public function createAccount(
		int $requestId,
		string $name,
		string $email,
		User $handler,
		bool $ignoreTitleBlacklist,
		bool $ignoreAntiSpoof,
		WebRequest $webRequest
	): Status {
		if ( !$this->authManager->canCreateAccounts() ) {
			return Status::newFatal( 'authmanager-create-disabled' );
		}

		// mailed by us so the approval email is customizable
		$passwordRequest = TemporaryPasswordAuthenticationRequest::newRandom();
		$passwordRequest->username = $name;
		$passwordRequest->mailpassword = false;
		$passwordRequest->caller = $handler->getName();

		// AuthManager only reads the username from a request declaring that field
		$usernameRequest = new UsernameAuthenticationRequest();
		$usernameRequest->username = $name;

		$requestTitle = SpecialPage::getTitleFor( 'RequestAccountQueue', (string)$requestId );
		$reasonRequest = new CreationReasonAuthenticationRequest();
		$reasonRequest->reason = $this->messageLocalizer->msg( 'requestaccount-creation-reason' )
			->params( "[[{$requestTitle->getPrefixedText()}|#$requestId]]" )
			->inContentLanguage()
			->text();

		$authRequests = [ $usernameRequest, $passwordRequest, $reasonRequest ];

		if ( $ignoreTitleBlacklist ) {
			$titleBlacklistRequest = new TitleBlacklistAuthenticationRequest();
			$titleBlacklistRequest->ignoreTitleBlacklist = true;
			$authRequests[] = $titleBlacklistRequest;
		}

		if ( $ignoreAntiSpoof ) {
			$antiSpoofRequest = new AntiSpoofAuthenticationRequest();
			$antiSpoofRequest->ignoreAntiSpoof = true;
			$authRequests[] = $antiSpoofRequest;
		}

		$response = $this->beginAccountCreation( $handler, $authRequests, $requestTitle->getFullURL() );

		if ( $response->status !== AuthenticationResponse::PASS ) {
			// A provider may have created the account but still asks for input
			// that only the requester could give. AuthManager keeps the creation
			// state for such a result, so clear it to keep the next creation
			// from resuming this one. FAIL results have no state to resume.
			// @phan-suppress-next-line PhanAccessClassConstantInternal
			$webRequest->getSession()->remove( AuthManager::ACCOUNT_CREATION_STATE );
		}

		if ( $response->status === AuthenticationResponse::FAIL ) {
			return Status::newFatal( $response->message );
		}

		// a provider may still ask for input the requester would have to give,
		// but secondary providers only run once the account exists
		$user = $this->userFactory->newFromName( $name );
		$user?->load( IDBAccessObject::READ_LATEST );
		if ( !$user || !$user->isRegistered() ) {
			return Status::newFatal( 'requestaccount-error-create-unexpected' );
		}

		// set only now, or core would mail a confirmation request
		$user->setEmail( $email );
		$user->confirmEmail();
		// also updates the global account via CentralAuth
		$user->saveSettings();

		return Status::newGood( $passwordRequest->password );
	}

	/**
	 * @param User $handler
	 * @param AuthenticationRequest[] $authRequests
	 * @param string $returnToUrl
	 * @return AuthenticationResponse
	 */
	private function beginAccountCreation(
		User $handler,
		array $authRequests,
		string $returnToUrl
	): AuthenticationResponse {
		$this->creating = true;
		try {
			return $this->authManager->beginAccountCreation( $handler, $authRequests, $returnToUrl );
		} finally {
			$this->creating = false;
		}
	}
}
