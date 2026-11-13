<?php

namespace Miraheze\MirahezeRequests\Tests\Integration;

use Generator;
use MediaWikiIntegrationTestCase;

/**
 * @group Database
 * @coversNothing
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideService */
	public function testService( string $name ): void {
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideService(): Generator {
		$wiring = require __DIR__ . '/../../../includes/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
