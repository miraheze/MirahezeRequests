<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditCanUserSkipCaptchaHook;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditTriggersCaptchaHook;
use MediaWiki\Page\PageIdentity;
use MediaWiki\User\User;
use Miraheze\MirahezeRequests\Requests\AccountCreator;

class ConfirmEdit implements
	ConfirmEditCanUserSkipCaptchaHook,
	ConfirmEditTriggersCaptchaHook
{

	public const string TRIGGER = 'requestaccount';

	public function __construct(
		private readonly Config $config,
		private readonly AccountCreator $accountCreator,
	) {
	}

	/**
	 * @inheritDoc
	 * @param User $user @phan-unused-param
	 */
	public function onConfirmEditCanUserSkipCaptcha( $user, &$result ): void {
		// the requester already solved one, and the handler can't
		if ( $this->accountCreator->isCreating() ) {
			$result = true;
		}
	}

	/**
	 * @inheritDoc
	 * @param string $action
	 * @param PageIdentity|null $page @phan-unused-param
	 */
	public function onConfirmEditTriggersCaptcha( string $action, $page, bool &$result ): void {
		// ConfirmEdit ignores triggers registered by manifest v2 extensions
		if ( $action === self::TRIGGER && !isset( $this->config->get( 'CaptchaTriggers' )[self::TRIGGER] ) ) {
			$result = true;
		}
	}
}
