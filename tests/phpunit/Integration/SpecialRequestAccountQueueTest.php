<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Exception\PermissionsError;
use MediaWiki\Request\FauxRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserIdentity;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use SpecialPageTestBase;
use StatusValue;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Specials\Queue\SpecialRequestAccountQueue
 * @covers \Miraheze\MirahezeRequests\Specials\Viewer\RequestAccountViewer
 */
class SpecialRequestAccountQueueTest extends SpecialPageTestBase {

	protected function setUp(): void {
		parent::setUp();
		$this->setGroupPermissions( 'sysop', 'handle-requestaccount', true );
	}

	/** @inheritDoc */
	protected function newSpecialPage(): SpecialPage {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( 'RequestAccountQueue' );
	}

	private function getManager(): RequestAccountManager {
		return $this->getServiceContainer()->get( 'RequestAccountManager' );
	}

	private function newRequest(
		string $name,
		string $email,
		bool $confirm,
		?UserIdentity $requester,
		string $ip = '127.0.0.1'
	): int {
		$manager = $this->getManager();
		[ $id ] = $manager->createUnconfirmed( [
			'request_timestamp' => $this->getDb()->timestamp(),
			'request_email' => $email,
			'request_username' => $name,
			'request_name_warnings' => '[]',
			'request_reason' => 'other',
			'request_explanation' => 'Test',
			'request_comments' => '',
			'request_ip' => $ip,
			'request_language' => 'en',
		], $requester );

		if ( $confirm ) {
			$manager->getById( $id );
			$this->assertStatusGood( $manager->confirm() );
		}

		return $id;
	}

	private function getRequestStatus( int $id ): string {
		$manager = $this->getManager();
		$manager->getById( $id );
		return $manager->getStatus();
	}

	/**
	 * @param string $par
	 * @param array $postData Posted if not empty; SpecialPageExecutor adds the edit token
	 * @return string HTML
	 */
	private function executeAsHandler( string $par, array $postData ): string {
		[ $html ] = $this->executeSpecialPage(
			$par,
			new FauxRequest( $postData + [ 'usesul3' => '0' ], (bool)$postData ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);
		return $html;
	}

	public function testRequiresHandleRight(): void {
		$this->expectException( PermissionsError::class );
		$this->executeSpecialPage( '', null, 'qqx', $this->getTestUser()->getAuthority() );
	}

	public function testRequestPageIsForHandlersAndTheRequester(): void {
		$requester = $this->getMutableTestUser()->getUser();
		$id = $this->newRequest( 'Own request', 'own@example.org', true, $requester );

		[ $html ] = $this->executeSpecialPage(
			(string)$id, new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx', $this->getTestUser()->getAuthority()
		);
		$this->assertStringContainsString( '(mirahezerequests-nopermission)', $html );
		$this->assertStringNotContainsString( 'own@example.org', $html );

		[ $html ] = $this->executeSpecialPage(
			(string)$id, new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx', $requester
		);
		$this->assertStringContainsString( 'own@example.org', $html );
		$this->assertStringNotContainsString( 'submit-accept', $html, 'Read-only for the requester' );
		$this->assertStringNotContainsString( 'requestaccount-label-same-email', $html );
	}

	public function testBlockedRequesterCanViewOwnRequest(): void {
		$requester = $this->getMutableTestUser()->getUser();
		$id = $this->newRequest( 'Blocked requester', 'blocked@example.org', true, $requester );

		$status = $this->getServiceContainer()->getBlockUserFactory()
			->newBlockUser(
				$requester->getName(),
				$this->getTestSysop()->getUser(),
				'indefinite',
				'Test block'
			)
			->placeBlock();
		$this->assertStatusGood( $status );

		$requester->clearInstanceCache();
		// Without an applied block the assertion below would pass for the wrong reason.
		$this->assertNotNull( $requester->getBlock() );

		[ $html ] = $this->executeSpecialPage(
			(string)$id,
			new FauxRequest( [ 'usesul3' => '0' ] ),
			'qqx',
			$requester
		);

		$this->assertStringContainsString( 'blocked@example.org', $html );
	}

	public function testLoggedOutRequestIsHandlersOnly(): void {
		$id = $this->newRequest( 'Logged out', 'loggedout@example.org', true, null );

		[ $html ] = $this->executeSpecialPage(
			(string)$id, new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx'
		);
		$this->assertStringContainsString( '(mirahezerequests-nopermission)', $html );
	}

	public function testQueueListsOnlyConfirmedRequests(): void {
		$this->newRequest( 'Queued', 'queued@example.org', true, null );
		$this->newRequest( 'Unconfirmed', 'unconfirmed@example.org', false, null );

		$views = [
			'default open view' => [],
			'pending filter' => [ 'status' => 'pending' ],
			'all view' => [ 'status' => '*', 'type' => 'all' ],
		];

		foreach ( $views as $label => $params ) {
			[ $html ] = $this->executeSpecialPage(
				'', new FauxRequest( $params ), 'qqx', $this->getTestSysop()->getAuthority()
			);
			$this->assertStringContainsString( 'queued@example.org', $html, $label );
			$this->assertStringNotContainsString( 'unconfirmed@example.org', $html, $label );
		}
	}

	public function testUnconfirmedRequestIsNotShown(): void {
		$id = $this->newRequest( 'Unconfirmed', 'unconfirmed@example.org', false, null );

		$this->assertStringContainsString( '(mirahezerequests-notfound)', $this->executeAsHandler( (string)$id, [] ) );
	}

	public function testAllViewIncludesOpenAndClosedRequests(): void {
		$this->newRequest( 'Open all view', 'allview@example.org', true, null );
		$closedId = $this->newRequest( 'Closed all view', 'allview@example.org', true, null );

		$manager = $this->getManager();
		$manager->getById( $closedId );
		$manager->transitionStatus(
			MirahezeRequestsStatus::Pending->value, MirahezeRequestsStatus::Declined->value
		);

		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [
				'email' => 'allview@example.org',
				'status' => '*',
				'type' => 'all',
			] ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( 'Open all view', $html );
		$this->assertStringContainsString( 'Closed all view', $html );
	}

	public function testFilterFormKeepsTheCurrentTab(): void {
		[ $html ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'type' => 'closed' ] ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( 'name="type"', $html );
		$this->assertStringContainsString( 'value="closed"', $html );
		$this->assertStringNotContainsString( 'name="wptype"', $html );
	}

	public function testUsernameOfZeroFiltersTheQueue(): void {
		$this->newRequest( '0', 'zero@example.org', true, null );
		$this->newRequest( 'Other name', 'other@example.org', true, null );

		[ $html ] = $this->executeSpecialPage(
			'', new FauxRequest( [ 'username' => '0' ] ), 'qqx', $this->getTestSysop()->getAuthority()
		);

		$this->assertStringContainsString( 'zero@example.org', $html );
		$this->assertStringNotContainsString( 'other@example.org', $html );
	}

	public function testLoggedOutRequestShowsAnonymousRequester(): void {
		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [] );
		$this->newRequest( 'Anonymous requester', 'anonymous@example.org', true, null );

		[ $html ] = $this->executeSpecialPage(
			'', null, 'qqx', $this->getTestSysop()->getAuthority()
		);
		$this->assertStringContainsString( '(mirahezerequests-requester-anonymous)', $html );

		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [ 'sysop' ] );
		[ $html ] = $this->executeSpecialPage(
			'', null, 'qqx', $this->getTestSysop()->getAuthority()
		);
		$this->assertStringNotContainsString( '(mirahezerequests-requester-anonymous)', $html );
		$this->assertStringContainsString( '127.0.0.1', $html );
	}

	public function testIpBlockDetailsFollowIpVisibility(): void {
		$id = $this->newRequest(
			'IpBlockProbe', 'ipblock@example.org', true, null, '203.0.113.77'
		);

		$status = $this->getServiceContainer()->getBlockUserFactory()
			->newBlockUser(
				'203.0.113.77',
				$this->getTestSysop()->getUser(),
				'indefinite',
				'Review probe block'
			)
			->placeBlock();
		$this->assertStatusGood( $status );

		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [] );
		[ $html ] = $this->executeSpecialPage(
			(string)$id, new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx', $this->getTestSysop()->getAuthority()
		);
		$this->assertStringNotContainsString( '203.0.113.77', $html );
		$this->assertStringNotContainsString( '(mirahezerequests-label-ip)', $html );
		$this->assertStringContainsString( '(requestaccount-warning-ip-blocked)', $html );

		$this->overrideConfigValue( 'MirahezeRequestsIPVisibilityGroups', [ 'sysop' ] );
		[ $html ] = $this->executeSpecialPage(
			(string)$id, new FauxRequest( [ 'usesul3' => '0' ] ), 'qqx', $this->getTestSysop()->getAuthority()
		);
		$this->assertStringContainsString( '203.0.113.77', $html );
		$this->assertStringContainsString( '(mirahezerequests-label-ip)', $html );
	}

	public function testFailedAcceptReleasesTheRequest(): void {
		$existingName = $this->getTestUser()->getUser()->getName();
		$id = $this->newRequest( $existingName, 'taken@example.org', true, null );

		$html = $this->executeAsHandler( (string)$id, [ 'wpsubmit-accept' => 1 ] );

		$this->assertStringContainsString( 'userexists', $html, 'The failure must be reported' );
		$this->assertSame( MirahezeRequestsStatus::Pending->value, $this->getRequestStatus( $id ) );
	}

	public function testDecline(): void {
		$id = $this->newRequest( 'Declined user', 'declined@example.org', true, null );

		$this->executeAsHandler( (string)$id, [
			'wpsubmit-decline' => 1,
			'wpsubmit-decline-reason' => 'Spam',
		] );

		$manager = $this->getManager();
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Declined->value, $manager->getStatus() );
		$this->assertSame( 'Spam', $manager->getNotes() );
	}

	public function testAcceptCreatesAccount(): void {
		$id = $this->newRequest( 'Accepted user', 'accepted@example.org', true, null );

		$this->executeAsHandler( (string)$id, [ 'wpsubmit-accept' => 1 ] );

		$this->assertSame( MirahezeRequestsStatus::Complete->value, $this->getRequestStatus( $id ) );
		$user = $this->getServiceContainer()->getUserFactory()->newFromName( 'Accepted user' );
		$this->assertTrue( $user->isRegistered() );
	}

	/** @dataProvider provideApprovalMailOutcome */
	public function testApprovalMailWaitsForCommit( bool $commit, bool $mailSucceeds ): void {
		$sent = [];
		$mailer = $this->createMock( AccountRequestMailer::class );
		$mailer->expects( $commit ? $this->once() : $this->never() )
			->method( 'sendApproved' )
			->willReturnCallback(
				static function ( RequestAccountManager $request, string $password ) use ( &$sent, $mailSucceeds ) {
					$sent[] = [ $request->getId(), $password ];
					return $mailSucceeds ? StatusValue::newGood() :
						StatusValue::newFatal( 'php-mail-error', 'Notification failure' );
				}
			);
		$this->setService( 'MirahezeRequestsAccountRequestMailer', $mailer );
		$id = $this->newRequest( 'Mail user' . mt_rand(), 'mail@example.org', true, null );
		$otherId = $this->newRequest( 'Other mail user' . mt_rand(), 'othermail@example.org', true, null );
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->beginPrimaryChanges( __METHOD__ );

		$this->executeAsHandler( (string)$id, [ 'wpsubmit-accept' => 1 ] );
		$this->assertSame( MirahezeRequestsStatus::Complete->value, $this->getRequestStatus( $id ) );
		$this->assertSame( [], $sent, 'Uncommitted accounts must not be mailed' );
		$this->getManager()->getById( $otherId );

		if ( !$commit ) {
			$lbFactory->rollbackPrimaryChanges( __METHOD__ );
			$this->assertSame( [], $sent );
			return;
		}

		$lbFactory->commitPrimaryChanges( __METHOD__ );
		$this->assertCount( 1, $sent );
		$this->assertSame( $id, $sent[0][0], 'Mail must use the approved request, not the shared manager' );
		$this->assertNotSame( '', $sent[0][1] );
		$manager = $this->getManager();
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Complete->value, $manager->getStatus() );
		if ( !$mailSucceeds ) {
			$this->assertStringContainsString( 'Notification failure', $manager->getNotes() );
		}
	}

	public static function provideApprovalMailOutcome(): array {
		return [
			'committed' => [ true, true ],
			'delivery failed' => [ true, false ],
			'rolled back' => [ false, true ],
		];
	}
}
