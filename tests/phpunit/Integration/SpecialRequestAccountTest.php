<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\FieldValidator;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Specials\SpecialRequestAccount;
use SpecialPageTestBase;
use StatusValue;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Specials\SpecialRequestAccount
 */
class SpecialRequestAccountTest extends SpecialPageTestBase {

	protected function setUp(): void {
		parent::setUp();
		$this->overrideConfigValue( MainConfigNames::EnableEmail, true );
	}

	/** @inheritDoc */
	protected function newSpecialPage(): SpecialPage {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'RequestAccount' );
	}

	private function getSpecialPage(): SpecialRequestAccount {
		/** @var SpecialRequestAccount $page */
		$page = $this->newSpecialPage();
		$this->assertInstanceOf( SpecialRequestAccount::class, $page );
		return $page;
	}

	public function testEmptyUsernameIsRejected(): void {
		$result = $this->getSpecialPage()->isValidUsername( '' );
		$this->assertInstanceOf( StatusValue::class, $result );
		$this->assertStatusError( 'htmlform-required', $result );
	}

	public function testNullUsernameIsRejected(): void {
		$result = $this->getSpecialPage()->isValidUsername( null );
		$this->assertInstanceOf( StatusValue::class, $result );
		$this->assertStatusError( 'htmlform-required', $result );
	}

	/**
	 * Regression test: validation used to check `!$username`, also
	 * true for "0", which incorrectly rejected it as missing.
	 */
	public function testUsernameOfZeroIsNotTreatedAsMissing(): void {
		$result = $this->getSpecialPage()->isValidUsername( '0' );

		$this->assertTrue( $result, '"0" is a legal username, not an empty one' );
	}

	public function testAFreshUniqueUsernameIsAccepted(): void {
		$username = 'MirahezeRequestsFreshUser' . mt_rand( 1, 1000000 );
		$result = $this->getSpecialPage()->isValidUsername( $username );

		$this->assertTrue( $result );
	}

	public function testAnAlreadyRegisteredUsernameIsRejectedAsTaken(): void {
		$existingUsername = $this->getTestUser()->getUser()->getName();

		$result = $this->getSpecialPage()->isValidUsername( $existingUsername );

		$this->assertInstanceOf( StatusValue::class, $result );
		$this->assertStatusError( 'userexists', $result );
	}

	public function testOffersCcOnlyToLoggedInUsers(): void {
		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx', $this->getTestUser()->getAuthority()
		);
		$this->assertStringContainsString( '(requestaccount-username)', $html );
		$this->assertStringContainsString( '(requestaccount-ccemail)', $html );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx'
		);
		$this->assertStringNotContainsString( '(requestaccount-ccemail)', $html );
	}

	public function testClosedHidesTheForm(): void {
		$this->overrideConfigValue( 'MirahezeRequestsAccountRequestsOpen', false );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx'
		);
		$this->assertStringContainsString( '(requestaccount-closed)', $html );
		$this->assertStringNotContainsString( '(requestaccount-username)', $html );
	}

	public function testOverlongExplanationIsRejected(): void {
		$name = 'OverlongExplanation' . mt_rand();

		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [
				'wpusername' => $name,
				'wpemail' => 'overlong' . mt_rand() . '@example.org',
				'wpreason' => 'other',
				'wpexplanation' => str_repeat( 'A', FieldValidator::MAX_TEXT_LENGTH + 1 ),
				'wpcomments' => '',
				'wpconsent' => 1,
				'usesul3' => '0',
			], true ),
			'qqx'
		);

		$this->assertStringContainsString( '(mirahezerequests-error-too-long', $html );

		$count = $this->getDb()->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'account_requests' )
			->where( [ 'request_username' => $name ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->assertSame( 0, (int)$count );
	}

	public function testConfirmationPutsRequestIntoQueueOnce(): void {
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		[ $id, $token ] = $manager->createUnconfirmed( [
			'request_timestamp' => $this->getDb()->timestamp(),
			'request_email' => 'confirm@example.com',
			'request_username' => 'ConfirmUser' . mt_rand( 1, 1000000 ),
			'request_name_warnings' => '[]',
			'request_reason' => 'other',
			'request_explanation' => 'Test',
			'request_comments' => '',
			'request_ip' => '127.0.0.1',
			'request_language' => 'en',
		], null );

		// opening the link alone must not confirm
		$this->executeSpecialPage( "confirm/$token", null, 'qqx' );
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Unconfirmed->value, $manager->getStatus() );

		[ $html ] = $this->executeSpecialPage( "confirm/$token", new FauxRequest( [], true ), 'qqx' );
		$this->assertStringContainsString( '(requestaccount-confirm-success)', $html );
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Pending->value, $manager->getStatus() );

		[ $html ] = $this->executeSpecialPage( "confirm/$token", new FauxRequest( [], true ), 'qqx' );
		$this->assertStringContainsString( '(requestaccount-confirm-invalid)', $html );
	}
}
