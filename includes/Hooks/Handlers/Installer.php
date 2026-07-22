<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	public function onLoadExtensionSchemaUpdates( DatabaseUpdater $updater ) {
		$dir = __DIR__ . '/../../../sql';
		$dbType = $updater->getDB()->getType();

		$updater->addExtensionTable(
			'renamewiki_requests',
			"$dir/$dbType/tables-generated.sql"
		);

		$updater->addExtensionTable(
			'account_requests',
			"$dir/$dbType/tables-generated.sql"
		);
	}
}
