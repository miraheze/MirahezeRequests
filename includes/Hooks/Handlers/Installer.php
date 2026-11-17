<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
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
