<?php

namespace Miraheze\MirahezeRequests\Specials;

use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class SpecialRequestAccount extends SpecialRequest {

	public function __construct( MirahezeRequestsDatabaseService $dbService	) {
		parent::__construct( 'Account', 'request-account', $dbService );
	}

	protected function getFormFields(): array {
		return [
			'email' => [
				'type' => 'text',
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
			'request_status' => self::STATUS_PENDING,
		];
	}
}
