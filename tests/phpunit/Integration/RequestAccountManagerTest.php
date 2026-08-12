<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Requests\RequestAccountManager
 */
class RequestAccountManagerTest extends MediaWikiIntegrationTestCase {

	private function getManager(): RequestAccountManager {
		// Fresh instance: row state lives on the object, not the service.
		return $this->getServiceContainer()->get( 'RequestAccountManager' );
	}

	private function insertRequest( array $overrides = [] ): int {
		$dbw = $this->getServiceContainer()->get( 'MirahezeRequestsDatabaseService' )->getDbw();

		$row = array_merge( [
			'request_actor' => $this->getTestUser()->getUser()->getActorId(),
			'request_timestamp' => $dbw->timestamp(),
			'request_email' => 'requester@example.com',
			'request_username' => 'MirahezeRequestsTestUser' . mt_rand( 1, 1000000 ),
			'request_reason' => 'other',
			'request_explanation' => 'I could not create an account for testing reasons.',
			'request_comments' => '',
			'request_ccemail' => 0,
			'request_ip' => '127.0.0.1',
			'request_status' => MirahezeRequestsStatus::Pending->value,
		], $overrides );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'account_requests' )
			->row( $row )
			->caller( __METHOD__ )
			->execute();

		return (int)$dbw->insertId();
	}

	public function testUserExistsIsFalseForAUsernameThatWasNeverRegistered(): void {
		$id = $this->insertRequest( [ 'request_username' => 'ThisUserDoesNotExist' . mt_rand( 1, 1000000 ) ] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertFalse( $manager->userExists() );
	}

	public function testUserExistsIsTrueOnceTheRequestedUsernameIsRegistered(): void {
		$testUser = $this->getTestUser()->getUser();
		$id = $this->insertRequest( [ 'request_username' => $testUser->getName() ] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertTrue( $manager->userExists() );
	}

	/**
	 * @dataProvider provideFinalizedStatuses
	 */
	public function testIsFinalized( string $status, bool $expected ): void {
		$id = $this->insertRequest( [ 'request_status' => $status ] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertSame( $expected, $manager->isFinalized() );
	}

	public static function provideFinalizedStatuses(): array {
		return [
			'pending is not finalized' => [ MirahezeRequestsStatus::Pending->value, false ],
			'inprogress is not finalized' => [ MirahezeRequestsStatus::InProgress->value, false ],
			'starting is finalized (already being processed)' => [ MirahezeRequestsStatus::Starting->value, true ],
			'complete is finalized' => [ MirahezeRequestsStatus::Complete->value, true ],
			'declined is finalized' => [ MirahezeRequestsStatus::Declined->value, true ],
			'failed is not finalized (can be retried)' => [ MirahezeRequestsStatus::Failed->value, false ],
		];
	}

	public function testCanSeeIpIsFalseWhenNoGroupsAreConfigured(): void {
		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [] );

		$manager = $this->getManager();
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();

		$this->assertFalse( $manager->canSeeIp( $user ) );
	}

	public function testCanSeeIpIsFalseWhenUserIsNotInAConfiguredGroup(): void {
		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [ 'checkuser' ] );

		$manager = $this->getManager();
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();

		$this->assertFalse( $manager->canSeeIp( $user ) );
	}

	public function testCanSeeIpIsTrueWhenUserIsInAConfiguredGroup(): void {
		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [ 'sysop' ] );

		$manager = $this->getManager();
		$user = $this->getTestUser( [ 'sysop' ] )->getUser();

		$this->assertTrue( $manager->canSeeIp( $user ) );
	}

	public function testResolvePersistsStatusPerformerAndNotesToTheDatabase(): void {
		$id = $this->insertRequest();
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

	public function testGetRequesterCcEmailIsNullWhenTheRequesterDidNotOptIn(): void {
		$id = $this->insertRequest( [ 'request_ccemail' => 0 ] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertNull( $manager->getRequesterCcEmail() );
	}

	public function testGetRequesterCcEmailIsNullWhenTheRequesterHasNoConfirmedEmail(): void {
		// A freshly created test user has no confirmed email by default.
		$requester = $this->getTestUser()->getUser();
		$id = $this->insertRequest( [
			'request_actor' => $requester->getActorId(),
			'request_ccemail' => 1,
		] );

		$manager = $this->getManager();
		$manager->getById( $id );

		$this->assertNull( $manager->getRequesterCcEmail() );
	}
}
