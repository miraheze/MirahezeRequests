<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Auth\Hook\ExemptFromAccountCreationThrottleHook;
use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\User\Hook\UserGetReservedNamesHook;
use Miraheze\MirahezeRequests\MirahezeRequestsConstants;
use Miraheze\MirahezeRequests\Requests\AccountCreator;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class Main implements
	ExemptFromAccountCreationThrottleHook,
	GetAllBlockActionsHook,
	UserGetReservedNamesHook
{

	public function __construct(
		private readonly MirahezeRequestsDatabaseService $dbService,
		private readonly AccountCreator $accountCreator,
	) {
	}

	/**
	 * @inheritDoc
	 * @suppress PhanUnusedPublicMethodParameter
	 */
	public function onExemptFromAccountCreationThrottle( $ip ): bool {
		// keyed on the handler's IP, it would cap approvals per day
		return !$this->accountCreator->isCreating();
	}

	/**
	 * @inheritDoc
	 */
	public function onGetAllBlockActions( &$actions ): void {
		if ( !$this->dbService->isCentralWiki() ) {
			return;
		}

		// 200 is used by Extension:ImportDump.
		$actions['request-account'] = 250;
	}

	/**
	 * @inheritDoc
	 */
	public function onUserGetReservedNames( &$reservedUsernames ): void {
		$reservedUsernames[] = MirahezeRequestsConstants::SYSTEM_USER;
	}
}
