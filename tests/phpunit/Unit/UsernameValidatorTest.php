<?php

namespace Miraheze\MirahezeRequests\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\MirahezeRequests\Requests\UsernameValidator;
use StatusValue;

/**
 * @covers \Miraheze\MirahezeRequests\Requests\UsernameValidator
 */
class UsernameValidatorTest extends MediaWikiUnitTestCase {

	public function testGetOverrideRight(): void {
		$this->assertSame(
			'override-antispoof',
			UsernameValidator::getOverrideRight( StatusValue::newFatal( 'antispoof-conflict' ) )
		);
		$this->assertSame(
			'tboverride',
			UsernameValidator::getOverrideRight( StatusValue::newFatal( 'titleblacklist-forbidden-new-account' ) )
		);
		$this->assertNull( UsernameValidator::getOverrideRight( StatusValue::newFatal( 'userexists' ) ) );
	}
}
