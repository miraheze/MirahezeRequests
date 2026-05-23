<?php

namespace Miraheze\MirahezeRequests\Services;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IReadableDatabase;

class MirahezeRequestsDatabaseService {
	private readonly IConnectionProvider $connectionProvider;
	private IReadableDatabase $dbr;
	private IDatabase $dbw;

	public function __construct( IConnectionProvider $connectionProvider ) {
		$this->connectionProvider = $connectionProvider;
		$this->dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-mirahezerequests' );
		$this->dbw = $this->connectionProvider->getPrimaryDatabase( 'virtual-mirahezerequests' );
	}

	/**
	 * @throws ErrorPageError
	 */
	public function isCentralDB(): bool|ErrorPageError {
		if ( !WikiMap::isCurrentWikiDbDomain( $this->dbr->getDomainID() ) ) {
			throw new ErrorPageError( 'mirahezerequests-notcentral', 'mirahezerequests-notcentral-text' );
		}
		return true;
	}

	public function getDbw(): IDatabase {
		return $this->dbw;
	}

	public function getDbr(): IReadableDatabase {
		return $this->dbr;
	}
}
