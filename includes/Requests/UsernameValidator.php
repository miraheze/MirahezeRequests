<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Api\IApiMessage;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserNameUtils;
use StatusValue;
use Wikimedia\ScopedCallback;

/** Allows TitleBlacklist and AntiSpoof refusals only, as handlers can override them. */
class UsernameValidator {

	public function __construct(
		private readonly AuthManager $authManager,
		private readonly PermissionManager $permissionManager,
		private readonly StatusFormatter $statusFormatter,
		private readonly UserNameUtils $userNameUtils,
	) {
	}

	/**
	 * @param string $name
	 * @param UserIdentity $requester The main context's user, whose rights the providers check
	 * @return StatusValue Good with [ 'name' => canonical name, 'warnings' => HTML[] ]
	 */
	public function validate( string $name, UserIdentity $requester ): StatusValue {
		$canonicalName = $this->userNameUtils->getCanonical( $name, UserNameUtils::RIGOR_CREATABLE );
		if ( $canonicalName === false ) {
			return StatusValue::newFatal( 'requestaccount-username-invalid' );
		}

		$warnings = [];
		$overrideRights = [];
		$scope = null;

		while ( true ) {
			$status = $this->authManager->canCreateAccount( $canonicalName );
			$right = $status->isGood() ? null : self::getOverrideRight( $status );

			if ( $status->isGood() || $right === null || in_array( $right, $overrideRights, true ) ) {
				ScopedCallback::consume( $scope );
				return $status->isGood() ?
					StatusValue::newGood( [ 'name' => $canonicalName, 'warnings' => $warnings ] ) :
					$status;
			}

			$warnings[] = $this->statusFormatter->getMessage( $status )->inContentLanguage()->parse();

			// AuthManager stops at the first refusal, so recheck to find others
			$overrideRights[] = $right;
			$scope = $this->permissionManager->addTemporaryUserRights( $requester, $overrideRights );
		}
	}

	/**
	 * @return ?string The right overriding the refusal, if any
	 */
	public static function getOverrideRight( StatusValue $status ): ?string {
		foreach ( $status->getMessages() as $message ) {
			if (
				( $message instanceof IApiMessage && $message->getApiCode() === 'titleblacklist-forbidden' ) ||
				str_starts_with( $message->getKey(), 'titleblacklist-' )
			) {
				return 'tboverride';
			}

			if ( in_array( $message->getKey(), [ 'antispoof-conflict', 'antispoof-name-illegal' ], true ) ) {
				return 'override-antispoof';
			}
		}

		return null;
	}
}
