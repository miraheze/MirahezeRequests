<?php

namespace Miraheze\MirahezeRequests\Hooks;

use Miraheze\MirahezeRequests\RequestManager;

interface RenameWikiJobAfterRenameWikiHook {

	/**
	 * @param string $filePath
	 * @param RequestManager $requestManager
	 * @return void
	 */
	public function onRenameWikiJobAfterRenameWiki(
		string $filePath,
		RequestManager $requestManager
	): void;
}
