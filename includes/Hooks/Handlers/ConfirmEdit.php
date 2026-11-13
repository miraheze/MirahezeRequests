<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditCanUserSkipCaptchaHook;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditTriggersCaptchaHook;
use Miraheze\MirahezeRequests\Requests\AccountCreator;

class ConfirmEdit implements
	ConfirmEditCanUserSkipCaptchaHook,
	ConfirmEditTriggersCaptchaHook
{

	public const TRIGGER = 'requestaccount';

	public function __construct(
		private readonly Config $config,
		private readonly AccountCreator $accountCreator,
	) {
	}

	/**
	 * @inheritDoc
	 * @suppress PhanUnusedPublicMethodParameter
	 */
	public function onConfirmEditCanUserSkipCaptcha( $user, &$result ): void {
		// the requester already solved one, and the handler can't
		if ( $this->accountCreator->isCreating() ) {
			$result = true;
		}
	}

	/**
	 * @inheritDoc
	 * @suppress PhanUnusedPublicMethodParameter
	 */
	public function onConfirmEditTriggersCaptcha( string $action, $page, bool &$result ): void {
		// ConfirmEdit ignores triggers registered by manifest v2 extensions
		if ( $action === self::TRIGGER && !isset( $this->config->get( 'CaptchaTriggers' )[self::TRIGGER] ) ) {
			$result = true;
		}
	}
}
