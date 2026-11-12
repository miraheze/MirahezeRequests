<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\User\UserIdentity;
use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Requests\RequestAccountManager
 */
class RequestAccountManagerTest extends MediaWikiIntegrationTestCase {

	private function getManager(): RequestAccountManager {
		// Fresh instance: row state lives on the object, not the service.
		$this->resetServices();
		return $this->getServiceContainer()->get( 'RequestAccountManager' );
	}

	private function newRow( array $overrides = [] ): array {
		return array_merge( [
			'request_timestamp' => $this->getDb()->timestamp(),
			'request_email' => 'requester@example.com',
			'request_username' => 'MirahezeRequestsTestUser' . mt_rand( 1, 1000000 ),
			'request_name_warnings' => '[]',
			'request_reason' => 'other',
			'request_globalblock_reason' => null,
			'request_explanation' => 'I could not create an account for testing reasons.',
			'request_comments' => '',
			'request_ip' => '127.0.0.1',
			'request_language' => 'en',
		], $overrides );
	}

	/**
	 * @return array{0:int,1:string} The request ID and the confirmation token
	 */
	private function insertUnconfirmed( array $overrides = [] ): array {
		return $this->getManager()->createUnconfirmed( $this->newRow( $overrides ), null );
	}

	private function loadFrom( UserIdentity $requester, array $overrides ): RequestAccountManager {
		[ $id ] = $this->getManager()->createUnconfirmed( $this->newRow( $overrides ), $requester );
		$manager = $this->getManager();
		$manager->getById( $id );
		return $manager;
	}

	public function testLoggedOutRequesterIsNotRecorded(): void {
		[ $id ] = $this->insertUnconfirmed( [ 'request_ccemail' => 1 ] );

		$manager = $this->getManager();
		$manager->getById( $id );
		$this->assertNull( $manager->getRequester() );
		$this->assertNull( $manager->getRequesterCcEmail() );
	}

	public function testGetRequesterCcEmailIsNullWhenTheRequesterDidNotOptIn(): void {
		$requester = $this->getMutableTestUser()->getUser();
		$requester->setEmail( 'account@example.com' );
		$requester->confirmEmail();
		$requester->saveSettings();

		$manager = $this->loadFrom( $requester, [ 'request_ccemail' => 0 ] );
		$this->assertTrue( $requester->equals( $manager->getRequester() ) );
		$this->assertNull( $manager->getRequesterCcEmail() );
	}

	public function testGetRequesterCcEmailIsNullWhenTheRequesterHasNoConfirmedEmail(): void {
		// A freshly created test user has no confirmed email by default.
		$manager = $this->loadFrom( $this->getMutableTestUser()->getUser(), [ 'request_ccemail' => 1 ] );

		$this->assertNull( $manager->getRequesterCcEmail() );
	}

	public function testGetRequesterCcEmail(): void {
		$requester = $this->getMutableTestUser()->getUser();
		$requester->setEmail( 'account@example.com' );
		$requester->confirmEmail();
		$requester->saveSettings();

		$manager = $this->loadFrom( $requester, [ 'request_ccemail' => 1 ] );
		$this->assertSame( 'account@example.com', $manager->getRequesterCcEmail() );
	}

	private function insertPending( array $overrides = [] ): int {
		[ $id ] = $this->insertUnconfirmed( $overrides );
		$manager = $this->getManager();
		$manager->getById( $id );
		$this->assertStatusGood( $manager->confirm() );
		return $id;
	}

	public function testConfirmationFlow(): void {
		[ $id, $token ] = $this->insertUnconfirmed();

		$manager = $this->getManager();
		$this->assertSame( $id, $manager->findUnconfirmedIdByToken( $token ) );
		$this->assertNull( $manager->findUnconfirmedIdByToken( 'wrong' ) );

		$manager->getById( $id );
		$this->assertFalse( $manager->isInQueue(), 'Unconfirmed requests are not in the queue' );
		$this->assertStatusGood( $manager->confirm() );
		$this->assertTrue( $manager->isInQueue() );
		$this->assertStatusError( 'requestaccount-confirm-invalid', $manager->confirm() );
		$this->assertNull( $manager->findUnconfirmedIdByToken( $token ), 'The token is single use' );
	}

	public function testOnlyOneRequestForANameCanBeConfirmed(): void {
		$name = 'SharedName' . mt_rand();
		[ $firstId ] = $this->insertUnconfirmed( [ 'request_username' => $name ] );
		[ $secondId, $secondToken ] = $this->insertUnconfirmed( [ 'request_username' => $name ] );
		$first = $this->getManager();
		$first->getById( $firstId );
		$second = $this->getManager();
		$second->getById( $secondId );

		$this->assertStatusGood( $first->confirm() );
		$this->assertStatusError( 'requestaccount-error-duplicate-username', $second->confirm() );
		$this->assertTrue(
			$first->transitionStatus(
				MirahezeRequestsStatus::Pending->value,
				MirahezeRequestsStatus::Starting->value
			),
			'The first request must be claimed to test the starting state'
		);
		$this->assertStatusError( 'requestaccount-error-duplicate-username', $second->confirm() );

		$second->getById( $secondId );
		$this->assertSame( MirahezeRequestsStatus::Unconfirmed->value, $second->getStatus() );
		$this->assertSame( $secondId, $second->findUnconfirmedIdByToken( $secondToken ) );

		$first->resolve( MirahezeRequestsStatus::Declined->value, $this->getTestSysop()->getUser(), 'Declined' );
		$this->assertStatusGood( $second->confirm(), 'A closed request no longer reserves the name' );
	}

	public function testDeliveryNotesPreserveTheResolution(): void {
		$id = $this->insertPending();
		$manager = $this->getManager();
		$manager->getById( $id );
		$performer = $this->getTestSysop()->getUser();
		$manager->resolve( MirahezeRequestsStatus::Complete->value, $performer, 'Created' );
		$completedAt = $manager->getCompletedTimestamp();

		$manager->setNotes( 'Notification failed' );
		$manager->getById( $id );
		$this->assertSame( 'Notification failed', $manager->getNotes() );
		$this->assertSame( MirahezeRequestsStatus::Complete->value, $manager->getStatus() );
		$this->assertSame( $completedAt, $manager->getCompletedTimestamp() );
		$this->assertTrue( $performer->equals( $manager->getCompletedUser() ) );
	}

	public function testTransitionStatusIsAtomic(): void {
		$id = $this->insertPending();

		$first = $this->getManager();
		$first->getById( $id );
		$second = $this->getManager();
		$second->getById( $id );

		$this->assertTrue( $first->transitionStatus(
			MirahezeRequestsStatus::Pending->value, MirahezeRequestsStatus::Starting->value
		) );
		$this->assertFalse( $second->transitionStatus(
			MirahezeRequestsStatus::Pending->value, MirahezeRequestsStatus::Declined->value
		), 'Another handler cannot act on a claimed request' );
	}

	public function testDuplicateChecks(): void {
		$name = 'DuplicateCheckUser' . mt_rand( 1, 1000000 );
		$this->insertUnconfirmed( [ 'request_username' => $name, 'request_email' => 'dup@example.com' ] );

		$manager = $this->getManager();
		$this->assertTrue( $manager->hasActiveRequestForEmail( 'dup@example.com' ) );
		$this->assertFalse( $manager->hasActiveRequestForEmail( 'dup+other@example.com' ),
			'Addresses are compared as submitted' );
		$this->assertFalse( $manager->hasOpenRequestForName( $name ),
			'Unconfirmed requests do not reserve the username' );

		$this->insertPending( [ 'request_username' => $name, 'request_email' => 'other@example.com' ] );
		$this->assertTrue( $manager->hasOpenRequestForName( $name ) );
	}

	public function testGetOtherRequestsWithSameEmail(): void {
		$declinedId = $this->insertPending( [ 'request_email' => 'same@example.com' ] );
		$manager = $this->getManager();
		$manager->getById( $declinedId );
		$manager->transitionStatus( MirahezeRequestsStatus::Pending->value, MirahezeRequestsStatus::Declined->value );

		$this->insertUnconfirmed( [ 'request_email' => 'same@example.com' ] );
		$currentId = $this->insertPending( [ 'request_email' => 'same@example.com' ] );

		$manager = $this->getManager();
		$manager->getById( $currentId );
		$this->assertSame(
			[ [ 'id' => $declinedId, 'status' => MirahezeRequestsStatus::Declined->value ] ],
			$manager->getOtherRequestsWithSameEmail( 10 )
		);
	}

	public function testPurgeExpiredData(): void {
		$this->overrideConfigValues( [
			'MirahezeRequestsAccountConfirmationExpiry' => 3600,
			'MirahezeRequestsIPRetentionDays' => 1,
		] );

		ConvertibleTimestamp::setFakeTime( '20250101000000' );
		[ $expiredId ] = $this->insertUnconfirmed( [ 'request_timestamp' => '20250101000000' ] );
		$handledId = $this->insertPending( [ 'request_timestamp' => '20250101000000' ] );
		$manager = $this->getManager();
		$manager->getById( $handledId );
		$manager->resolve( MirahezeRequestsStatus::Declined->value, $this->getTestSysop()->getUser(), '' );

		ConvertibleTimestamp::setFakeTime( '20250103000000' );
		$this->getManager()->purgeExpiredData();

		$manager = $this->getManager();
		$manager->getById( $expiredId );
		$this->assertFalse( $manager->exists() );
		$manager->getById( $handledId );
		$this->assertSame( '', $manager->getIp() );
	}

	public function testPurgeExpiredDataClearsIpOfUnhandledRequests(): void {
		$this->overrideConfigValues( [
			'MirahezeRequestsAccountConfirmationExpiry' => 3600,
			'MirahezeRequestsIPRetentionDays' => 1,
		] );

		ConvertibleTimestamp::setFakeTime( '20250101000000' );
		$staleOpenId = $this->insertPending( [ 'request_timestamp' => '20250101000000' ] );
		$handledLaterId = $this->insertPending( [ 'request_timestamp' => '20250101000000' ] );

		ConvertibleTimestamp::setFakeTime( '20250103000000' );
		$freshOpenId = $this->insertPending( [ 'request_timestamp' => '20250103000000' ] );

		// handled long after submission, so its retention period has not passed
		$manager = $this->getManager();
		$manager->getById( $handledLaterId );
		$manager->resolve( MirahezeRequestsStatus::Declined->value, $this->getTestSysop()->getUser(), '' );

		$this->getManager()->purgeExpiredData();

		$manager = $this->getManager();

		$manager->getById( $staleOpenId );
		$this->assertSame( '', $manager->getIp(), 'An unhandled request must not keep its IP forever' );

		$manager->getById( $freshOpenId );
		$this->assertSame( '127.0.0.1', $manager->getIp(), 'A recent open request keeps its IP' );

		$manager->getById( $handledLaterId );
		$this->assertSame( '127.0.0.1', $manager->getIp(), 'Handling restarts the retention period' );
	}

	public function testUserExistsIsFalseForAUsernameThatWasNeverRegistered(): void {
		$id = $this->insertPending( [ 'request_username' => 'ThisUserDoesNotExist' . mt_rand( 1, 1000000 ) ] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertFalse( $manager->userExists() );
	}

	public function testUserExistsIsTrueOnceTheRequestedUsernameIsRegistered(): void {
		$testUser = $this->getTestUser()->getUser();
		$id = $this->insertPending( [ 'request_username' => $testUser->getName() ] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertTrue( $manager->userExists() );
	}

	/**
	 * @dataProvider provideFinalizedStatuses
	 */
	public function testIsFinalized( string $status, bool $expected ): void {
		$id = $this->insertPending();
		$manager = $this->getManager();
		$manager->getById( $id );
		$manager->transitionStatus( MirahezeRequestsStatus::Pending->value, $status );

		$this->assertSame( $expected, $manager->isFinalized() );
	}

	public static function provideFinalizedStatuses(): array {
		return [
			'pending is not finalized' => [ MirahezeRequestsStatus::Pending->value, false ],
			'starting is finalized (already being processed)' => [ MirahezeRequestsStatus::Starting->value, true ],
			'complete is finalized' => [ MirahezeRequestsStatus::Complete->value, true ],
			'declined is finalized' => [ MirahezeRequestsStatus::Declined->value, true ],
		];
	}

	/**
	 * @dataProvider provideCanSeeIp
	 */
	public function testCanSeeIp( array $configuredGroups, bool $expected ): void {
		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', $configuredGroups );

		$manager = $this->getManager();
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();

		$this->assertSame( $expected, $manager->canSeeIp( $user ) );
	}

	public static function provideCanSeeIp(): array {
		return [
			'no groups configured' => [ [], false ],
			'user not in a configured group' => [ [ 'checkuser' ], false ],
			'user in a configured group' => [ [ 'sysop' ], true ],
		];
	}

	public function testResolvePersistsStatusPerformerAndNotesToTheDatabase(): void {
		$id = $this->insertPending();
		$performer = $this->getTestUser( [ 'sysop' ] )->getUser();

		$manager = $this->getManager();
		$manager->getById( $id );
		$manager->resolve( MirahezeRequestsStatus::Declined->value, $performer, 'Not a valid reason.' );

		$this->assertSame( MirahezeRequestsStatus::Declined->value, $manager->getStatus() );
		$this->assertSame( 'Not a valid reason.', $manager->getNotes() );
		$this->assertTrue( $performer->equals( $manager->getCompletedUser() ) );

		// Confirm the DB row was actually updated, not just the object.
		$reloaded = $this->getManager();
		$reloaded->getById( $id );

		$this->assertSame( MirahezeRequestsStatus::Declined->value, $reloaded->getStatus() );
		$this->assertSame( 'Not a valid reason.', $reloaded->getNotes() );
		$this->assertNotNull( $reloaded->getCompletedTimestamp() );
	}
}
