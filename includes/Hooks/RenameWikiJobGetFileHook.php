<?php

namespace Miraheze\MirahezeRequests\Hooks;

use Miraheze\MirahezeRequests\RequestManager;

interface RenameWikiJobGetFileHook {

	/**
	 * @param string &$filePath
	 * @param RequestManager $requestManager
	 * @return void
	 */
	public function onRenameWikiJobGetFile(
		string &$filePath,
		RequestManager $requestManager
	): void;
}
