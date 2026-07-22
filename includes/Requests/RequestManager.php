<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use stdClass;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\Platform\ISQLPlatform;

abstract class RequestManager {
	private IDatabase $dbw;
	protected stdClass $row;
	private int $Id;
	private string $table;

	public function __construct(
		readonly string $name,
		private readonly MirahezeRequestsDatabaseService $dbService,
		private readonly UserFactory $userFactory,
	) {
		$this->dbw = $this->dbService->getDbw();
		$this->table = $this->name . '_requests';
	}

	public function getById( int $requestID ): void {
		$this->Id = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( $this->table )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	public function getId(): int {
		return $this->Id;
	}

	public function getStatus() {
		return $this->row->request_status;
	}

	public function getRequester(): User {
		return $this->userFactory->newFromActorId( $this->row->request_actor );
	}

	public function getTimestamp() {
		return $this->row->request_timestamp;
	}

	public function setStatus( string $status ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( $this->table )
			->set( [ 'request_status' => $status ] )
			->where( [ 'request_id' => $this->Id ] )
			->caller( __METHOD__ )
			->execute();
	}
}
