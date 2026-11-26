<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Mail\MailAddress;
use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use StatusValue;

/** @covers \Miraheze\MirahezeRequests\Requests\AccountRequestMailer */
class AccountRequestMailerTest extends MediaWikiIntegrationTestCase {

	public function testApprovalSendsPasswordOnlyToTheRequestedAddress(): void {
		$sent = [];
		$emailer = $this->createMock( IEmailer::class );
		$emailer->method( 'send' )->willReturnCallback(
			static function (
				array $to, MailAddress $from, string $subject, string $body
			) use ( &$sent ): StatusValue {
				$sent[$to[0]->address] = $body;
				return StatusValue::newGood();
			}
		);

		$request = $this->createMock( RequestAccountManager::class );
		$request->method( 'getEmail' )->willReturn( 'requested@example.org' );
		$request->method( 'getUsername' )->willReturn( 'Mailer test' );
		$request->method( 'getLanguage' )->willReturn( 'qqx' );
		$request->method( 'getRequesterCcEmail' )->willReturn( 'requester@example.org' );

		$mailer = new AccountRequestMailer(
			$emailer,
			RequestContext::getMain(),
			new ServiceOptions(
				AccountRequestMailer::CONSTRUCTOR_OPTIONS,
				$this->getServiceContainer()->getMainConfig()
			)
		);
		$this->assertStatusGood( $mailer->sendApproved( $request, 'TestTempPass123' ) );

		$this->assertArrayHasKey( 'requested@example.org', $sent );
		$this->assertArrayHasKey( 'requester@example.org', $sent );
		$this->assertStringContainsString( 'TestTempPass123', $sent['requested@example.org'] );
		$this->assertStringContainsString( 'requestaccount-created-email-cc-text', $sent['requester@example.org'] );
		$this->assertStringNotContainsString( 'TestTempPass123', $sent['requester@example.org'] );
	}
}
