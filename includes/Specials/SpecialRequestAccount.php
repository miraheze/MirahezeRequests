<?php

namespace Miraheze\MirahezeRequests\Specials;

use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class SpecialRequestAccount extends SpecialRequest {

	public function __construct( MirahezeRequestsDatabaseService $dbService	) {
		parent::__construct( 'RequestAccount', 'request-account', $dbService );
	}

	protected function getFormFields(): array {
		return [
			'email' => [
				'type' => 'text',
				'label-message' => 'requestaccount-email',
				'required' => true,
			],
			'username' => [
				'type' => 'text',
				'label-message' => 'requestaccount-username',
				'required' => true,
			],
			'reason' => [
				'type' => 'radio',
				'label-message' => 'requestaccount-reason',
				'options-messages' => [
					'requestaccount-abusefilter-label' => 'abusefilter',
					'requestaccount-captcha-label' => 'captcha',
					'requestaccount-globalblock-label' => 'globalblock',
					'requestaccount-other-label' => 'other',
				],
				'required' => true,
			],
			'explanation' => [
				'type' => 'textarea',
				'label-message' => 'requestaccount-explanation',
				'help-message' => 'requestaccount-explanation-help',
				'required' => true,
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
			'request_status' => self::STATUS_PENDING,
		];
	}
}
