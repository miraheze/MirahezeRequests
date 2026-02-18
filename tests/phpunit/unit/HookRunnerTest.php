<?php

namespace Miraheze\MirahezeRequests\Tests\Unit;

use Generator;
use MediaWiki\Tests\HookContainer\HookRunnerTestBase;
use Miraheze\MirahezeRequests\Hooks\HookRunner;

/**
 * @covers \Miraheze\MirahezeRequests\Hooks\HookRunner
 */
class HookRunnerTest extends HookRunnerTestBase {

	/** @inheritDoc */
	public static function provideHookRunners(): Generator {
		yield HookRunner::class => [ HookRunner::class ];
	}
}
