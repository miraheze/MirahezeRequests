<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\Message\Message;
use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\FieldValidator;

/**
 * @covers \Miraheze\MirahezeRequests\FieldValidator
 */
class FieldValidatorTest extends MediaWikiIntegrationTestCase {

	public function testValidateEmail(): void {
		$messageLocalizer = RequestContext::getMain();

		$this->assertTrue( FieldValidator::validateEmail( ' user@example.org ', $messageLocalizer ) );
		$this->assertTrue( FieldValidator::validateEmail( '', $messageLocalizer ), 'Left to required' );

		$message = FieldValidator::validateEmail( 'not-an-email', $messageLocalizer );
		$this->assertInstanceOf( Message::class, $message );
		$this->assertSame( 'invalidemailaddress', $message->getKey() );

		$message = FieldValidator::validateEmail(
			str_repeat( 'a', 250 ) . '@example.org', $messageLocalizer
		);
		$this->assertInstanceOf( Message::class, $message );
		$this->assertSame( 'invalidemailaddress', $message->getKey(), 'Longer than the database column' );
	}

	public function testValidateLength(): void {
		$messageLocalizer = RequestContext::getMain();

		$this->assertTrue( FieldValidator::validateLength( 'あいう', 3, $messageLocalizer ), 'Counts characters' );

		$message = FieldValidator::validateLength( 'abcd', 3, $messageLocalizer );
		$this->assertInstanceOf( Message::class, $message );
		$this->assertSame( 'mirahezerequests-error-too-long', $message->getKey() );
		$this->assertStringContainsString( '3', $message->text(), 'The limit is shown to the user' );
	}
}
