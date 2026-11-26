<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\PasswordAuthenticationRequest;
use MediaWiki\Config\SiteConfiguration;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\CentralAuth\Config\CAMainConfigNames;
use MediaWiki\Extension\CentralAuth\User\CentralAuthUser;
use MediaWiki\MainConfigNames;
use MediaWiki\Request\FauxRequest;
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

	private function configureDomain( bool $sharedDomain ): void {
		if ( !$this->getServiceContainer()->getExtensionRegistry()->isLoaded( 'CentralAuth' ) ) {
			$this->markTestSkipped( 'CentralAuth is not available' );
		}

		$this->overrideConfigValues( [
			CAMainConfigNames::CentralAuthSharedDomainCallback =>
				static fn ( $wiki ) => 'https://auth.example.org/metawiki',
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
		// SUL3 changes the current globals; WikiMap must resolve the wiki's original settings.
		$conf = new SiteConfiguration();
		$conf->suffixes = [ '' ];
		$conf->settings = [
			'wgServer' => [ 'default' => 'https://meta.example.org' ],
			'wgCanonicalServer' => [ 'default' => 'https://meta.example.org' ],
			'wgArticlePath' => [ 'default' => '/wiki/$1' ],
		];
		$this->setMwGlobals( 'wgConf', $conf );
		$this->setGroupPermissions( 'sysop', 'handle-requestaccount', true );
	}

	/** @dataProvider provideAuthenticationPage */
	public function testLocalPagesRedirectToAuth( string $pageName, string $subpage ): void {
		$this->pageName = $pageName;
		$this->configureDomain( false );
		[ , $response ] = $this->executeSpecialPage(
			$subpage, new FauxRequest( [ 'uselang' => 'ja' ] )
		);

		$url = $response->getHeader( 'Location' );
		$this->assertStringStartsWith(
			'https://auth.example.org/metawiki/wiki/Special:' . $pageName .
				( $subpage !== '' ? '/' . $subpage : '' ) . '?',
			$url
		);
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'uselang' => 'ja' ], $query );
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
			new FauxRequest( [ 'wpsubmit-accept' => '1' ], true ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);
	}

	public function testSharedDomainRendersRequestFormWithoutRedirect(): void {
		$this->configureDomain( true );
		[ $html, $response ] = $this->executeSpecialPage();
		$this->assertNull( $response->getHeader( 'Location' ) );
		$this->assertStringContainsString( '(requestaccount-username)', $html );
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
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		$id = $this->newConfirmedRequest( $manager, 'SUL3 queue filter' );
		[ , $response ] = $this->executeSpecialPage(
			'',
			new FauxRequest( [ 'type' => 'closed', 'email' => 'sul3@example.org' ] ),
			'qqx',
			$this->getTestSysop()->getAuthority()
		);
		$url = $response->getHeader( 'Location' );
		$this->assertStringStartsWith( 'https://meta.example.org/wiki/Special:RequestAccountQueue?', $url );
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'type' => 'closed', 'emailRequestId' => (string)$id ], $query );
	}

	public function testSharedDomainApprovalCreatesGlobalAccountAndReturnsToMeta(): void {
		$this->pageName = 'RequestAccountQueue';
		// Exercise CentralAuth's providers instead of the generic PHPUnit provider configuration.
		$this->overrideConfigValue( MainConfigNames::AuthManagerConfig, null );
		$this->configureDomain( true );
		$sentPassword = null;
		$mailer = $this->createMock( AccountRequestMailer::class );
		$mailer->expects( $this->once() )->method( 'sendApproved' )
			->willReturnCallback( static function (
				RequestAccountManager $request, string $password
			) use ( &$sentPassword ): StatusValue {
				$sentPassword = $password;
				return StatusValue::newGood();
			} );
		$this->setService( 'MirahezeRequestsAccountRequestMailer', $mailer );
		$name = 'SUL3 approved user';
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		$id = $this->newConfirmedRequest( $manager, $name );
		$request = new FauxRequest( [ 'wpsubmit-accept' => '1' ], true );
		$this->setRequest( $request );
		$handler = $this->getTestSysop()->getUser();
		$lbFactory = $this->getServiceContainer()->getDBLoadBalancerFactory();
		$lbFactory->beginPrimaryChanges( __METHOD__ );
		[ , $response ] = $this->executeSpecialPage( (string)$id, $request, 'qqx', $handler );
		$manager->getById( $id );
		$this->assertSame( MirahezeRequestsStatus::Sending->value, $manager->getStatus() );
		$this->assertNull( $sentPassword, 'Uncommitted global accounts must not be mailed' );
		$lbFactory->commitPrimaryChanges( __METHOD__ );
		$this->assertIsString( $sentPassword );
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

		$login = new PasswordAuthenticationRequest();
		$login->action = AuthManager::ACTION_LOGIN;
		$login->username = $name;
		$login->password = $sentPassword;
		$authResponse = $this->getServiceContainer()->getAuthManager()->beginAuthentication(
			[ $login ], 'https://meta.example.org/'
		);
		$this->assertSame( AuthenticationResponse::UI, $authResponse->status );
		$this->assertSame( 'resetpass-temp-emailed', $authResponse->message?->getKey() );
	}

	public function testSharedDomainApprovalRequiresValidCsrfToken(): void {
		$this->pageName = 'RequestAccountQueue';
		$this->configureDomain( true );
		$manager = $this->getServiceContainer()->get( 'RequestAccountManager' );
		$name = 'SUL3 invalid token user';
		$id = $this->newConfirmedRequest( $manager, $name );
		$request = new FauxRequest( [
			'wpsubmit-accept' => '1', 'wpEditToken' => 'invalid',
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
