<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Miraheze\MirahezeRequests\MirahezeRequestsConstants;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class Main implements
	GetAllBlockActionsHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly MirahezeRequestsDatabaseService $dbService
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onGetAllBlockActions( &$actions ): void {
		if ( !$this->dbService->isCentralWiki() ) {
			return;
		}

		// 200 is already used by Extension:ImportDump; pick an ID that
		// doesn't collide with any other block-action registered on
		// Miraheze wikis.
		$actions['request-account'] = 250;
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGetReservedNames( &$reservedUsernames ): void {
		$reservedUsernames[] = MirahezeRequestsConstants::SYSTEM_USER;
	}
}
