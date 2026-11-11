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
	protected ?stdClass $row = null;
	private int $Id;
	private string $table;

	public function __construct(
		readonly string $name,
		private readonly MirahezeRequestsDatabaseService $dbService,
		private readonly UserFactory $userFactory,
	) {
		$this->dbw = $this->dbService->getDbw();
		$this->table = $name . '_requests';
	}

	public function getById( int $requestID ): void {
		$this->Id = $requestID;

		$this->row = $this->dbw->newSelectQueryBuilder()
			->select( ISQLPlatform::ALL_ROWS )
			->from( $this->table )
			->where( [ 'request_id' => $requestID ] )
			->caller( __METHOD__ )
			->fetchRow() ?: null;
	}

	public function exists(): bool {
		return $this->row !== null;
	}

	public function getId(): int {
		return $this->Id;
	}

	public function getStatus(): string {
		return $this->row->request_status;
	}

	/**
	 * @return ?User Null if the request was submitted while logged out
	 */
	public function getRequester(): ?User {
		if ( $this->row->request_actor === null ) {
			return null;
		}

		return $this->userFactory->newFromActorId( (int)$this->row->request_actor );
	}

	public function getTimestamp(): string {
		return $this->row->request_timestamp;
	}

	public function setStatus( string $status ): void {
		$this->dbw->newUpdateQueryBuilder()
			->update( $this->table )
			->set( [ 'request_status' => $status ] )
			->where( [ 'request_id' => $this->Id ] )
			->caller( __METHOD__ )
			->execute();

		if ( $this->row !== null ) {
			$this->row->request_status = $status;
		}
	}
}
