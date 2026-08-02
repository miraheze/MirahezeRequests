<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Message\Message;
use MediaWiki\User\UserNameUtils;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class SpecialRequestAccount extends SpecialRequest {

	public function __construct(
		MirahezeRequestsDatabaseService $dbService,
		private readonly UserNameUtils $userNameUtils,
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
				'validation-callback' => [ $this, 'isValidUsername' ],
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

	public function isValidUsername( ?string $username ): Message|true {
		if ( !$username ) {
			return $this->msg( 'htmlform-required' );
		}

		if ( !$this->userNameUtils->isCreatable( $username ) ) {
			return $this->msg( 'requestaccount-username-invalid' );
		}

		return true;
	}

	protected function getRequestTable(): string {
		return 'account_requests';
	}

	protected function getInsertRow( array $data, $timestamp ): array {
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
			'request_status' => self::STATUS_PENDING,
		];
	}
}
