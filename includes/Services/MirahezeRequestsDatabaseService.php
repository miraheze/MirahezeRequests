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
	 * Whether this wiki is the one the virtual-mirahezerequests
	 * domain resolves to, wherever that domain is physically mapped.
	 */
	public function isCentralWiki(): bool {
		return WikiMap::isCurrentWikiDbDomain( $this->dbr->getDomainID() );
	}

	/**
	 * @throws ErrorPageError if this isn't the central wiki
	 */
	public function isCentralDB(): true {
		if ( !$this->isCentralWiki() ) {
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
