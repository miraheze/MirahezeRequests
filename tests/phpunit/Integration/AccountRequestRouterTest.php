<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\CentralAuth\Config\CAMainConfigNames;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Site\MediaWikiSite;
use MediaWiki\Site\SiteLookup;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use SpecialPageTestBase;
use StatusValue;

/**
 * @group Database
 * @covers \Miraheze\MirahezeRequests\Specials\AccountRequestRouter
 * @covers \Miraheze\MirahezeRequests\Specials\SpecialRequestAccount
 * @covers \Miraheze\MirahezeRequests\Specials\Queue\SpecialRequestAccountQueue
 */
class AccountRequestRouterTest extends SpecialPageTestBase {

	private string $pageName = 'RequestAccount';

	/** @inheritDoc */
	protected function newSpecialPage(): SpecialPage {
		return $this->getServiceContainer()->getSpecialPageFactory()->getPage( $this->pageName );
	}

	private function configureDomain( bool $sharedDomain, bool $sul3Available = true ): void {
		if ( !$this->getServiceContainer()->getExtensionRegistry()->isLoaded( 'CentralAuth' ) ) {
			$this->markTestSkipped( 'CentralAuth is not available' );
		}

		$this->overrideConfigValues( [
			CAMainConfigNames::CentralAuthSharedDomainCallback => $sul3Available ?
				static fn ( $wiki ) => 'https://auth.example.org/metawiki' : false,
			CAMainConfigNames::CentralAuthEnableSul3 => true,
			CAMainConfigNames::CentralAuthRestrictSharedDomain => true,
			CAMainConfigNames::CentralAuthSul3SharedDomainRestrictions => [
				'allowedSpecialPages' => [ 'RequestAccount', 'RequestAccountQueue' ],
			],
			MainConfigNames::EnableEmail => true,
			MainConfigNames::Server => $sharedDomain ? 'https://auth.example.org' : 'https://meta.example.org',
			MainConfigNames::CanonicalServer => $sharedDomain ? 'https://auth.example.org' : 'https://meta.example.org',
			MainConfigNames::ArticlePath => $sharedDomain ? '/metawiki/wiki/$1' : '/wiki/$1',
			MainConfigNames::ScriptPath => $sharedDomain ? '/metawiki/w' : '/w',
			MainConfigNames::Script => $sharedDomain ? '/metawiki/w/index.php' : '/w/index.php',
		] );
		$site = new MediaWikiSite();
		$site->setPagePath( 'https://meta.example.org/wiki/$1' );
		$siteLookup = $this->createMock( SiteLookup::class );
		$siteLookup->method( 'getSite' )->willReturn( $site );
		$this->setService( 'SiteLookup', $siteLookup );
		$this->setGroupPermissions( 'sysop', 'handle-requestaccount', true );
	}

	/** @dataProvider provideAuthenticationPage */
	public function testLocalPagesRedirectToAuth( string $pageName, string $subpage ): void {
		$this->pageName = $pageName;
		$this->configureDomain( false );
		[ , $response ] = $this->executeSpecialPage(
			$subpage, new FauxRequest( [ 'title' => 'Special:Userlogin', 'usesul3' => '0', 'uselang' => 'ja' ] )
		);

		$url = $response->getHeader( 'Location' );
		$this->assertStringStartsWith(
			'https://auth.example.org/metawiki/wiki/Special:' . $pageName .
				( $subpage !== '' ? '/' . $subpage : '' ) . '?',
			$url
		);
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'usesul3' => '1', 'uselang' => 'ja' ], $query );
	}

	public static function provideAuthenticationPage(): array {
		return [
			'new request' => [ 'RequestAccount', '' ],
			'email confirmation' => [ 'RequestAccount', 'confirm/exampletoken' ],
			'handling' => [ 'RequestAccountQueue', '123' ],
		];
	}

	/** @dataProvider provideAuthenticationPage */
	public function testLocalPostsAreRejected( string $pageName, string $subpage ): void {
		$this->pageName = $pageName;
		$this->configureDomain( false );
		$this->expectException( ErrorPageError::class );
		$this->executeSpecialPage(
			$subpage,
			new FauxRequest( [ 'usesul3' => '0', 'wpsubmit-accept' => '1' ], true ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);
	}

	public function testSharedDomainRendersRequestFormWithoutRedirect(): void {
		$this->configureDomain( true );
		[ $html, $response ] = $this->executeSpecialPage();
		$this->assertNull( $response->getHeader( 'Location' ) );
		$this->assertStringContainsString( '(requestaccount-username)', $html );
		$this->assertSelectorMatchesOneElement( $html, 'input[name="usesul3"][value="1"]' );
	}

	public function testSharedDomainNormalizesLegacySul2LinkWithoutDuplicatingWikiPrefix(): void {
		$this->configureDomain( true );
		[ , $response ] = $this->executeSpecialPage( '', new FauxRequest( [ 'usesul3' => '0' ] ) );
		$this->assertSame(
			'https://auth.example.org/metawiki/wiki/Special:RequestAccount?usesul3=1',
			$response->getHeader( 'Location' )
		);
	}

	public function testSharedDomainRejectsSul2Post(): void {
		$this->configureDomain( true );
		$this->expectException( ErrorPageError::class );
		$this->executeSpecialPage( '', new FauxRequest( [ 'usesul3' => '0' ], true ) );
	}

	public function testLocalQueueStaysOnMeta(): void {
		$this->pageName = 'RequestAccountQueue';
		$this->configureDomain( false );
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		$this->newConfirmedRequest( $manager, 'SUL3 queued user' );
		[ $html, $response ] = $this->executeSpecialPage( '', null, 'qqx', $this->getTestSysop()->getAuthority() );
		$this->assertNull( $response->getHeader( 'Location' ) );
		$this->assertStringContainsString( '(requestaccount-username-short)', $html );
		$this->assertStringContainsString( 'SUL3 queued user', $html );
		$this->assertStringContainsString( 'sul3@example.org', $html );
	}

	public function testSharedQueueReturnsToMetaWithFilters(): void {
		$this->pageName = 'RequestAccountQueue';
		$this->configureDomain( true );
		[ , $response ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'type' => 'closed', 'email' => 'test@example.org', 'usesul3' => '1' ] ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);
		$url = $response->getHeader( 'Location' );
		$this->assertStringStartsWith( 'https://meta.example.org/wiki/Special:RequestAccountQueue?', $url );
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'type' => 'closed', 'email' => 'test@example.org' ], $query );
	}

	public function testSharedQueueFailsWhenWikiIsUnregistered(): void {
		$this->configureDomain( true );
		$this->setMwGlobals( 'wgConf', new SiteConfiguration() );
		$siteLookup = $this->createMock( SiteLookup::class );
		$siteLookup->method( 'getSite' )->willReturn( null );
		$this->setService( 'SiteLookup', $siteLookup );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'The request queue wiki must be registered in WikiMap.' );
		$this->getServiceContainer()->get( 'MirahezeRequestsAccountRequestRouter' )->getQueueURL( [] );
	}

	public function testWikiWithoutSul3RendersRequestLocally(): void {
		$this->configureDomain( false, false );
		[ $html, $response ] = $this->executeSpecialPage();
		$this->assertNull( $response->getHeader( 'Location' ) );
		$this->assertStringContainsString( '(requestaccount-username)', $html );
	}

	public function testSharedDomainApprovalCreatesGlobalAccountAndReturnsToMeta(): void {
		$this->pageName = 'RequestAccountQueue';
		// Exercise CentralAuth's providers instead of the generic PHPUnit provider configuration.
		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, null );
		$this->configureDomain( true );
		$sent = false;
		$mailer = $this->createMock( AccountRequestMailer::class );
		$mailer->expects( $this->once() )->method( 'sendApproved' )
			->willReturnCallback( static function () use ( &$sent ) {
				$sent = true;
				return StatusValue::newGood();
			} );
		$this->setService( 'MirahezeRequestsAccountRequestMailer', $mailer );
		$name = 'SUL3 approved user';
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		$id = $this->newConfirmedRequest( $manager, $name );
		$request = new FauxRequest( [ 'usesul3' => '1', 'wpsubmit-accept' => '1' ], true );
		$this->setRequest( $request );
		$handler = $this->getTestSysop()->getUser();
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->beginPrimaryChanges( __METHOD__ );
		[ , $response ] = $this->executeSpecialPage( (string)$id, $request, 'qqx', $handler );
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Sending->value, $manager->getStatus() );
		$this->assertFalse( $sent, 'Uncommitted global accounts must not be mailed' );
		$lbFactory->commitPrimaryChanges( __METHOD__ );
		$this->assertTrue( $sent );
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Complete->value, $manager->getStatus() );
		$centralUser = CentralAuthUser::getPrimaryInstanceByName( $name );
		$this->assertTrue( $centralUser->exists() );
		$this->assertTrue( $centralUser->isAttached() );
		$this->assertSame( 'sul3@example.org', $centralUser->getEmail() );
		$this->assertNotNull( $centralUser->getEmailAuthenticationTimestamp() );
		$this->assertTrue( $handler->equals( $request->getSession()->getUser() ) );
		$this->assertSame(
			'https://meta.example.org/wiki/Special:RequestAccountQueue', $response->getHeader( 'Location' )
		);
	}

	public function testSharedDomainApprovalRequiresValidCsrfToken(): void {
		$this->pageName = 'RequestAccountQueue';
		$this->configureDomain( true );
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		$name = 'SUL3 invalid token user';
		$id = $this->newConfirmedRequest( $manager, $name );
		$request = new FauxRequest( [
			'usesul3' => '1', 'wpsubmit-accept' => '1', 'wpEditToken' => 'invalid',
		], true );
		$this->setRequest( $request );
		$this->executeSpecialPage( (string)$id, $request, 'qqx', $this->getTestSysop()->getAuthority() );
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Pending->value, $manager->getStatus() );
		$this->assertFalse( $this->getServiceContainer()->getUserFactory()->newFromName( $name )->isRegistered() );
	}

	private function newConfirmedRequest( RequestAccountManager $manager, string $name ): int {
		[ $id ] = $manager->createUnconfirmed( [
			'request_timestamp' => $this->getDb()->timestamp(),
			'request_email' => 'sul3@example.org',
			'request_username' => $name,
			'request_name_warnings' => '[]',
			'request_reason' => 'other',
			'request_explanation' => 'Test',
			'request_comments' => '',
			'request_ip' => '127.0.0.1',
			'request_language' => 'en',
		], null );
		$manager->getById( $id );
		$this->assertStatusGood( $manager->confirm() );
		return $id;
	}
}
