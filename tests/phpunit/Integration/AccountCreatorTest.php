<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Auth\AbstractSecondaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\UsernameAuthenticationRequest;
use MediaWiki\Context\RequestContext;
use MediaWiki\Language\RawMessage;
use MediaWiki\MainConfigNames;
use MediaWiki\Status\Status;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Requests\AccountCreator
 */
class AccountCreatorTest extends MediaWikiIntegrationTestCase {

	private function createAccount( string $name ): Status {
		return $this->getServiceContainer()->get( 'MirahezeRequestsAccountCreator' )->createAccount(
			1,
			$name,
			'new@example.org',
			$this->getTestSysop()->getUser(),
			ignoreTitleBlacklist: false,
			ignoreAntiSpoof: false,
			webRequest: RequestContext::getMain()->getRequest()
		);
	}

	private function assertCreatedWithConfirmedEmail( string $name ): void {
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( $name );
		$user->load( IDBAccessObject::READ_LATEST );
		$this->assertTrue( $user->isRegistered() );
		$this->assertSame( 'new@example.org', $user->getEmail() );
		$this->assertTrue( $user->isEmailConfirmed() );
	}

	public function testCreatesAccountWithConfirmedEmail(): void {
		$status = $this->createAccount( 'Requested user' );

		$this->assertStatusGood( $status );
		$this->assertIsString( $status->getValue(), 'The temporary password' );
		$this->assertCreatedWithConfirmedEmail( 'Requested user' );
	}

	/**
	 * @suppress PhanAccessClassConstantInternal
	 */
	public function testAccountCreatedBeforeProviderAsksForInput(): void {
		// tests use core's providers only
		$authManagerConfig = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::AuthManagerConfig );
		$authManagerConfig['secondaryauth']['AsksForInput'] = [
			'factory' => static fn () => new class extends AbstractSecondaryAuthenticationProvider {

				/** @inheritDoc */
				public function getAuthenticationRequests( $action, array $options ) {
					return [];
				}

				/** @inheritDoc */
				public function beginSecondaryAuthentication( $user, array $reqs ) {
					return AuthenticationResponse::newAbstain();
				}

				/** @inheritDoc */
				public function beginSecondaryAccountCreation( $user, $creator, array $reqs ) {
					return AuthenticationResponse::newUI(
						[ new UsernameAuthenticationRequest() ],
						new RawMessage( 'More input' )
					);
				}
			},
		];
		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, $authManagerConfig );

		$status = $this->createAccount( 'Interrupted user' );

		$this->assertStatusGood( $status );
		$this->assertCreatedWithConfirmedEmail( 'Interrupted user' );
		$this->assertNull(
			RequestContext::getMain()->getRequest()->getSession()->get( AuthManager::ACCOUNT_CREATION_STATE ),
			'The creation session is cleared even when a provider asks for input'
		);
	}

	public function testRefusesExistingAccount(): void {
		// existing afterwards doesn't mean created
		$existingName = $this->getTestUser()->getUser()->getName();

		$this->assertStatusError( 'userexists', $this->createAccount( $existingName ) );
	}
}
