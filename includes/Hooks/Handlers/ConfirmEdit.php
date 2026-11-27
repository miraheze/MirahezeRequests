<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ConfirmEdit\Hooks\ConfirmEditTriggersCaptchaHook;
use MediaWiki\Page\PageIdentity;

class ConfirmEdit implements ConfirmEditTriggersCaptchaHook {

	public const string TRIGGER = 'requestaccount';

	public function __construct(
		private readonly Config $config,
	) {
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
