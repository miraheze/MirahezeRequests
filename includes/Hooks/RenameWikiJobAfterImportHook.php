<?php

namespace Miraheze\MirahezeRequests\Hooks;

use Miraheze\MirahezeRequests\RequestManager;

interface RenameWikiJobAfterImportHook {

	/**
	 * @param string $filePath
	 * @param RequestManager $requestManager
	 * @return void
	 */
	public function onRenameWikiJobAfterImport(
		string $filePath,
		RequestManager $requestManager
	): void;
}
