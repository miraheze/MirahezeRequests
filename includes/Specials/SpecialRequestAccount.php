<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Message\Message;
use MediaWiki\Status\StatusValue;
use MediaWiki\Title\Title;
use MediaWiki\User\CentralId\CentralIdLookup;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class SpecialRequestAccount extends SpecialRequest {

	public function __construct(
		MirahezeRequestsDatabaseService $dbService,
		private readonly UserNameUtils $userNameUtils,
		private readonly UserFactory $userFactory,
		private readonly CentralIdLookup $centralIdLookup,
	) {
		parent::__construct( 'Account', 'request-account', $dbService );
	}

	protected function getFormFields(): array {
		return [
			'email' => [
				'type' => 'email',
				'label-message' => 'requestaccount-email',
				'required' => true,
			],
			'reason' => [
				'type' => 'radio',
				'label-message' => 'requestaccount-reason',
				'help-message' => 'requestaccount-reason-help',
				'options-messages' => [
					'requestaccount-other-label' => 'other',
					'requestaccount-abusefilter-label' => 'abusefilter',
					'requestaccount-captcha-label' => 'captcha',
					'requestaccount-globalblock-label' => 'globalblock',
				],
				'default' => 'other',
				'required' => true,
			],
			'explanation' => [
				'type' => 'textarea',
				'label-message' => 'requestaccount-explanation',
				'help-message' => 'requestaccount-explanation-help',
				'required' => true,
			],
			'username' => [
				'type' => 'text',
				'label-message' => 'requestaccount-username',
				'help-message' => 'requestaccount-username-help',
				'required' => true,
				'validation-callback' => $this->isValidUsername( ... ),
			],
			'comments' => [
				'type' => 'textarea',
				'label-message' => 'requestaccount-comments',
			],
			'CCemail' => [
				'type' => 'check',
				'label-message' => 'requestaccount-ccemail',
			],
			'consent' => [
				'type' => 'check',
				'label-message' => 'requestaccount-consent',
				'required' => true,
			]
		];
	}

	/**
	 * Runs the actual username validation and returns the result as a
	 * StatusValue, so the checks themselves stay independent of HTMLForm
	 * and are easier to test/reuse. isValidUsername() below adapts the
	 * result to what HTMLForm's validation-callback expects.
	 */
	private function validateUsername( ?string $username ): StatusValue {
		// Deliberately not `!$username`: that is also true for the
		// string "0", which is a legal (if unusual) username.
		if ( $username === null || $username === '' ) {
			return StatusValue::newFatal( 'htmlform-required' );
		}

		if ( !$this->userNameUtils->isCreatable( $username ) ) {
			return StatusValue::newFatal( 'requestaccount-username-invalid' );
		}

		if ( $this->userFactory->newFromName( $username )?->isRegistered() ) {
			return StatusValue::newFatal( 'requestaccount-username-taken' );
		}

		if ( $this->centralIdLookup->centralIdFromName( $username ) !== 0 ) {
			return StatusValue::newFatal( 'requestaccount-username-taken' );
		}

		$title = Title::makeTitleSafe( NS_USER, $username );
		if ( $title && $this->isBlacklisted( $title ) ) {
			return StatusValue::newFatal( 'requestaccount-username-blacklisted' );
		}

		return StatusValue::newGood();
	}

	/**
	 * Checks the username against Extension:TitleBlacklist (which also
	 * covers the global title blacklist, when configured as a source)
	 * and Extension:AntiSpoof, if either is installed. Both are optional
	 * dependencies: nothing here requires them to be present.
	 */
	private function isBlacklisted( Title $title ): bool {
		if ( class_exists( \MediaWiki\Extension\TitleBlacklist\TitleBlacklist::class ) ) {
			$blacklist = \MediaWiki\Extension\TitleBlacklist\TitleBlacklist::singleton()
				->userCannot( $title, $this->getUser(), 'create' );
			if ( $blacklist ) {
				return true;
			}
		}

		if ( class_exists( \MediaWiki\Extension\AntiSpoof\SpoofUser::class ) ) {
			$spoofUser = new \MediaWiki\Extension\AntiSpoof\SpoofUser( $title->getText() );
			if ( $spoofUser->getConflicts() ) {
				return true;
			}
		}

		return false;
	}

	public function isValidUsername( ?string $username ): Message|true {
		$status = $this->validateUsername( $username );
		if ( $status->isGood() ) {
			return true;
		}

		// getMessages() is typed MessageSpecifier[], not Message[], so
		// normalize explicitly rather than assuming what it returns -
		// HTMLForm's validation-callback contract wants a real Message.
		return Message::newFromSpecifier( $status->getMessages()[0] );
	}

	protected function getInsertRow( array $data, string $timestamp ): array {
		return [
			'request_actor' => $this->getUser()->getActorId(),
			'request_timestamp' => $timestamp,
			'request_email' => $data['email'],
			'request_username' => $data['username'],
			'request_reason' => $data['reason'],
			'request_explanation' => $data['explanation'],
			'request_comments' => $data['comments'],
			'request_ccemail' => (int)$data['CCemail'],
			'request_ip' => $this->getRequest()->getIP(),
			'request_status' => MirahezeRequestsStatus::Pending->value,
		];
	}
}
