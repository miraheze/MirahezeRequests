<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 * @param DatabaseUpdater $updater
	 *
	 * $updater isn't type-hinted because the hook interface doesn't
	 * type it either; adding a type here narrows it and throws a fatal
	 * "Declaration must be compatible" error.
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$dir = __DIR__ . '/../../../sql';
		$dbType = $updater->getDB()->getType();

		// account_requests lives on the virtual-mirahezerequests domain.
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-mirahezerequests',
			'addTable',
			'account_requests',
			"$dir/$dbType/tables-generated.sql",
			true,
		] );
	}
}
