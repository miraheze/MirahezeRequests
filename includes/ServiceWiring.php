<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsValidator;

return [
	'RequestAccountManager' => static function ( MediaWikiServices $services ): RequestAccountManager {
		return new RequestAccountManager(
			$services->getJobQueueGroupFactory(),
			$services->get( 'MirahezeRequestsDatabaseService' ),
			$services->getUserFactory(),
			$services->getBlockManager()
		);
	},
	'MirahezeRequestsConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'MirahezeRequests' );
	},
	'MirahezeRequestsDatabaseService' => static function ( MediaWikiServices $services ): MirahezeRequestsDatabaseService {
		return new MirahezeRequestsDatabaseService( $services->getConnectionProvider() );
	},
	'MirahezeRequestsValidator' => static function ( MediaWikiServices $services ) {
		return new MirahezeRequestsValidator(
			RequestContext::getMain(),
			new ServiceOptions(
				MirahezeRequestsValidator::CONSTRUCTOR_OPTIONS,
				$services->get( 'MirahezeRequestsConfig' )
			)
		);
	}
];
