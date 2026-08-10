<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

return [
	'RequestAccountManager' => static function ( MediaWikiServices $services ): RequestAccountManager {
		return new RequestAccountManager(
			$services->getJobQueueGroupFactory(),
			$services->get( 'MirahezeRequestsDatabaseService' ),
			$services->getUserFactory(),
			$services->getBlockManager(),
			$services->get( 'MirahezeRequestsConfig' ),
			$services->getUserGroupManager(),
			$services->getActorNormalization()
		);
	},
	'MirahezeRequestsConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'MirahezeRequests' );
	},
	'MirahezeRequestsDatabaseService' => static function ( MediaWikiServices $services ): MirahezeRequestsDatabaseService {
		return new MirahezeRequestsDatabaseService( $services->getConnectionProvider() );
	},
];
