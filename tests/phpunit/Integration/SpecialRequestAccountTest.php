<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Message\Message;
use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\Specials\SpecialRequestAccount;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Specials\SpecialRequestAccount
 */
class SpecialRequestAccountTest extends MediaWikiIntegrationTestCase {

	private function getSpecialPage(): SpecialRequestAccount {
		/** @var SpecialRequestAccount $page */
		$page = $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'RequestAccount' );
		$this->assertInstanceOf( SpecialRequestAccount::class, $page );
		return $page;
	}

	public function testEmptyUsernameIsRejected(): void {
		$result = $this->getSpecialPage()->isValidUsername( '' );
		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'htmlform-required', $result->getKey() );
	}

	public function testNullUsernameIsRejected(): void {
		$result = $this->getSpecialPage()->isValidUsername( null );
		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'htmlform-required', $result->getKey() );
	}

	/**
	 * Regression test: validation used to check `!$username`, also
	 * true for "0", which incorrectly rejected it as missing.
	 */
	public function testUsernameOfZeroIsNotTreatedAsMissing(): void {
		$result = $this->getSpecialPage()->isValidUsername( '0' );

		if ( $result instanceof Message ) {
			$this->assertNotSame( 'htmlform-required', $result->getKey() );
		} else {
			$this->assertTrue( $result );
		}
	}

	public function testAFreshUniqueUsernameIsAccepted(): void {
		$username = 'MirahezeRequestsFreshUser' . mt_rand( 1, 1000000 );
		$result = $this->getSpecialPage()->isValidUsername( $username );

		$this->assertTrue( $result );
	}

	public function testAnAlreadyRegisteredUsernameIsRejectedAsTaken(): void {
		$existingUsername = $this->getTestUser()->getUser()->getName();

		$result = $this->getSpecialPage()->isValidUsername( $existingUsername );

		$this->assertInstanceOf( Message::class, $result );
		$this->assertSame( 'requestaccount-username-taken', $result->getKey() );
	}
}
