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
		$this->assertSame( 'failed', MirahezeRequestsStatus::Failed->value );
		$this->assertSame( 'inprogress', MirahezeRequestsStatus::InProgress->value );
		$this->assertSame( 'pending', MirahezeRequestsStatus::Pending->value );
		$this->assertSame( 'starting', MirahezeRequestsStatus::Starting->value );
	}

	public function testOpenAndClosedPartitionAllCasesWithNoOverlap(): void {
		$allValues = array_map(
			static fn ( MirahezeRequestsStatus $case ): string => $case->value,
			MirahezeRequestsStatus::cases()
		);

		$union = array_merge( MirahezeRequestsStatus::OPEN, MirahezeRequestsStatus::CLOSED );
		sort( $allValues );
		sort( $union );

		$this->assertSame(
			$allValues,
			$union,
			'Every status must be classified as either open or closed, with no status missing or duplicated.'
		);

		$this->assertEmpty(
			array_intersect( MirahezeRequestsStatus::OPEN, MirahezeRequestsStatus::CLOSED ),
			'A status cannot be both open and closed at the same time.'
		);
	}

	/**
	 * @dataProvider provideCases
	 */
	public function testGetMessageKeyFormat( MirahezeRequestsStatus $case ): void {
		$this->assertSame( 'mirahezerequests-status-' . $case->value, $case->getMessageKey() );
	}

	public static function provideCases(): array {
		$cases = [];
		foreach ( MirahezeRequestsStatus::cases() as $case ) {
			$cases[$case->name] = [ $case ];
		}
		return $cases;
	}
}
