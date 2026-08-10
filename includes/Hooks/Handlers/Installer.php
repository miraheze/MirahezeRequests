<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Installer\DatabaseUpdater;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 *
	 * @param DatabaseUpdater $updater
	 *
	 * Note: intentionally not type-hinting $updater. The hook interface
	 * itself doesn't type it, and PHP doesn't allow an implementation to
	 * add a parameter type the interface doesn't declare (that narrows
	 * the accepted type, which breaks LSP) -- doing so throws a fatal
	 * "Declaration must be compatible" error at runtime.
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
		$dir = __DIR__ . '/../../../sql';
		$dbType = $updater->getDB()->getType();

		// account_requests lives on the 'virtual-mirahezerequests' virtual
		// domain, not necessarily the wiki's own database, so the table
		// update has to be registered against that domain specifically.
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-mirahezerequests',
			'addTable',
			'account_requests',
			"$dir/$dbType/tables-generated.sql",
			true,
		] );
	}
}
