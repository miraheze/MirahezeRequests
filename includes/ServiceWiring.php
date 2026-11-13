<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Config\Config;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\CentralAuth\CentralAuthServices;
use MediaWiki\MediaWikiServices;
use Miraheze\MirahezeRequests\Requests\AccountCreator;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Requests\UsernameValidator;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Miraheze\MirahezeRequests\Specials\AccountRequestRouter;

// PHPUnit does not understand coverage for this file.
// It is covered though, see ServiceWiringTest.
// @codeCoverageIgnoreStart

return [
	'MirahezeRequestsAccountRequestRouter' => static function ( MediaWikiServices $services ): AccountRequestRouter {
		return new AccountRequestRouter(
			$services->getExtensionRegistry()->isLoaded( 'CentralAuth' ) ?
				CentralAuthServices::getSharedDomainUtils( $services ) : null,
		);
	},
	'MirahezeRequestsAccountCreator' => static function ( MediaWikiServices $services ): AccountCreator {
		return new AccountCreator(
			$services->getAuthManager(),
			RequestContext::getMain(),
			$services->getUserFactory(),
		);
	},
	'MirahezeRequestsAccountRequestMailer' => static function ( MediaWikiServices $services ): AccountRequestMailer {
		return new AccountRequestMailer(
			$services->getEmailer(),
			RequestContext::getMain(),
			new ServiceOptions(
				AccountRequestMailer::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			),
		);
	},
	'MirahezeRequestsConfig' => static function ( MediaWikiServices $services ): Config {
		return $services->getConfigFactory()->makeConfig( 'MirahezeRequests' );
	},
	'MirahezeRequestsDatabaseService' => static function (
		MediaWikiServices $services
	): MirahezeRequestsDatabaseService {
		return new MirahezeRequestsDatabaseService( $services->getConnectionProvider() );
	},
	'MirahezeRequestsUsernameValidator' => static function ( MediaWikiServices $services ): UsernameValidator {
		return new UsernameValidator(
			$services->getAuthManager(),
			$services->getPermissionManager(),
			$services->getFormatterFactory()->getStatusFormatter( RequestContext::getMain() ),
			$services->getUserNameUtils(),
		);
	},
	'RequestAccountManager' => static function ( MediaWikiServices $services ): RequestAccountManager {
		return new RequestAccountManager(
			$services->get( 'MirahezeRequestsDatabaseService' ),
			$services->getUserFactory(),
			$services->getBlockManager(),
			$services->get( 'MirahezeRequestsConfig' ),
			$services->getUserGroupManager(),
			$services->getActorNormalization(),
			$services->getReadOnlyMode(),
		);
	},
];

// @codeCoverageIgnoreEnd
