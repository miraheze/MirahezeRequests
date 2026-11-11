<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Extension\TitleBlacklist\TitleBlacklist;
use MediaWiki\Extension\TitleBlacklist\TitleBlacklistPreAuthenticationProvider;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use StatusValue;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Requests\UsernameValidator
 */
class UsernameValidatorTest extends MediaWikiIntegrationTestCase {

	protected function tearDown(): void {
		if ( class_exists( TitleBlacklist::class ) ) {
			TitleBlacklist::destroySingleton();
		}
		parent::tearDown();
	}

	private function enableTitleBlacklist(): void {
		$this->markTestSkippedIfExtensionNotLoaded( 'TitleBlacklist' );
		TitleBlacklist::destroySingleton();

		// tests use core's providers only
		$authManagerConfig = $this->getServiceContainer()->getMainConfig()->get( MainConfigNames::AuthManagerConfig );
		$authManagerConfig['preauth']['TitleBlacklistPreAuthenticationProvider'] = [
			'class' => TitleBlacklistPreAuthenticationProvider::class,
		];

		$this->overrideConfigValues( [
			MainConfigNames::AuthManagerConfig => $authManagerConfig,
			'TitleBlacklistSources' => [
				[ 'type' => 'file', 'src' => __DIR__ . '/../data/titleblacklist.txt' ],
			],
		] );
	}

	private function validate( string $name ): StatusValue {
		$validator = $this->getServiceContainer()->get( 'MirahezeRequestsUsernameValidator' );
		return $validator->validate( $name, $this->getServiceContainer()->getUserFactory()->newAnonymous() );
	}

	public function testAcceptsCreatableName(): void {
		$status = $this->validate( 'fine name' );

		$this->assertStatusGood( $status );
		$this->assertSame( [ 'name' => 'Fine name', 'warnings' => [] ], $status->getValue() );
	}

	public function testAcceptsTitleBlacklistedNameWithWarning(): void {
		$this->enableTitleBlacklist();
		$status = $this->validate( 'Forbidden Name' );

		$this->assertStatusGood( $status );
		$this->assertSame( 'Forbidden Name', $status->getValue()['name'] );
		$this->assertCount( 1, $status->getValue()['warnings'] );
	}

	public function testRefusesInvalidName(): void {
		$this->assertStatusError( 'requestaccount-username-invalid', $this->validate( 'Bad#Name' ) );
	}

	public function testRefusesExistingName(): void {
		$this->assertStatusError( 'userexists', $this->validate( $this->getTestUser()->getUser()->getName() ) );
	}
}
