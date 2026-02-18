<?php

namespace Miraheze\MirahezeRequests\Tests;

use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\RequestManager;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @group RenameWiki
 * @group Database
 * @group medium
 * @coversDefaultClass \Miraheze\MirahezeRequests\RequestManager
 */
class RequestManagerTest extends MediaWikiIntegrationTestCase
	implements MirahezeRequestsStatus {

	public function addDBDataOnce(): void {
		$this->overrideConfigValue( MainConfigNames::VirtualDomainsMapping, [
			'virtual-mirahezerequests' => [ 'db' => 'wikidb' ],
		] );

		ConvertibleTimestamp::setFakeTime( ConvertibleTimestamp::now() );

		$connectionProvider = $this->getServiceContainer()->getConnectionProvider();
		$dbw = $connectionProvider->getPrimaryDatabase( 'virtual-mirahezerequests' );

		$dbw->newInsertQueryBuilder()
			->insertInto( 'renamewiki_requests' )
			->ignore()
			->row( [
				'request_source' => 'https://renamewikitest.com',
				'request_target' => 'renamewikitest',
				'request_reason' => 'test',
				'request_status' => self::STATUS_PENDING,
				'request_actor' => $this->getTestUser()->getUser()->getActorId(),
				'request_timestamp' => $this->db->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();
	}

	private function getRequestManager( int $id ): RequestManager {
		$manager = $this->getServiceContainer()->getService( 'RenameWikiRequestManager' );
		'@phan-var RequestManager $manager';
		$manager->loadFromID( $id );
		return $manager;
	}

	/**
	 * @covers ::__construct
	 */
	public function testConstructor(): void {
		$manager = $this->getServiceContainer()->getService( 'RenameWikiRequestManager' );
		$this->assertInstanceOf( RequestManager::class, $manager );
	}

	/**
	 * @covers ::loadFromID
	 */
	public function testLoadFromID(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertInstanceOf( RequestManager::class, $manager );
	}

	/**
	 * @covers ::exists
	 */
	public function testExists(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertTrue( $manager->exists() );

		$manager = $this->getRequestManager( id: 2 );
		$this->assertFalse( $manager->exists() );
	}

	/**
	 * @covers ::addComment
	 * @covers ::getComments
	 * @covers ::sendNotification
	 */
	public function testComments(): void {
		$manager = $this->getRequestManager( id: 1 );
		$this->assertArrayEquals( [], $manager->getComments() );

		$manager->addComment( 'Test', $this->getTestUser()->getUser() );
		$this->assertCount( 1, $manager->getComments() );
	}
}
