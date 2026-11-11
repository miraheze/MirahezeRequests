<?php

namespace Miraheze\MirahezeRequests\Tests\Unit;

use MediaWikiUnitTestCase;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;

/**
 * @covers \Miraheze\MirahezeRequests\MirahezeRequestsStatus
 */
class MirahezeRequestsStatusTest extends MediaWikiUnitTestCase {

	public function testValuesAreStableStrings(): void {
		// Persisted in the database; must not change without a migration.
		$this->assertSame( 'complete', MirahezeRequestsStatus::Complete->value );
		$this->assertSame( 'declined', MirahezeRequestsStatus::Declined->value );
		$this->assertSame( 'pending', MirahezeRequestsStatus::Pending->value );
		$this->assertSame( 'starting', MirahezeRequestsStatus::Starting->value );
		$this->assertSame( 'unconfirmed', MirahezeRequestsStatus::Unconfirmed->value );
	}

	public function testOpenAndClosedPartitionAllQueueCasesWithNoOverlap(): void {
		$allValues = array_map(
			static fn ( MirahezeRequestsStatus $case ): string => $case->value,
			MirahezeRequestsStatus::queueCases()
		);
		$allValues = array_values( $allValues );

		$union = array_merge( MirahezeRequestsStatus::OPEN, MirahezeRequestsStatus::CLOSED );
		sort( $allValues );
		sort( $union );

		$this->assertSame(
			$allValues,
			$union,
			'Every status must be classified as either open or closed, with no status missing or duplicated.'
		);

		$this->assertSame(
			[],
			array_intersect( MirahezeRequestsStatus::OPEN, MirahezeRequestsStatus::CLOSED ),
			'A status cannot be both open and closed at the same time.'
		);
	}
}
