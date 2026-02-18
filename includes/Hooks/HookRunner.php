<?php

namespace Miraheze\MirahezeRequests\Hooks;

use MediaWiki\HookContainer\HookContainer;
use Miraheze\MirahezeRequests\RequestManager;

class HookRunner implements
	RenameWikiJobAfterRenameWikiHook,
	RenameWikiJobGetFileHook
{

	public function __construct(
		private readonly HookContainer $container
	) {
	}

	/** @inheritDoc */
	public function onRenameWikiJobAfterRenameWiki(
		string $filePath,
		RequestManager $requestManager
	): void {
		$this->container->run(
			'RenameWikiJobAfterRenameWiki',
			[ $filePath, $requestManager ],
			[ 'abortable' => false ]
		);
	}

	/** @inheritDoc */
	public function onRenameWikiJobGetFile(
		string &$filePath,
		RequestManager $requestManager
	): void {
		$this->container->run(
			'RenameWikiJobGetFile',
			[ &$filePath, $requestManager ],
			[ 'abortable' => false ]
		);
	}
}
