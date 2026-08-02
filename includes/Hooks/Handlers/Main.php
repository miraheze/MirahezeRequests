<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use MediaWiki\WikiMap\WikiMap;
use Wikimedia\Rdbms\IConnectionProvider;

class Main implements
	GetAllBlockActionsHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/** @inheritDoc */
	public function onGetAllBlockActions( &$actions ) {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-mirahezerequests' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$actions['request-account'] = 200;
	}

	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'MirahezeRequests';
	}
}
