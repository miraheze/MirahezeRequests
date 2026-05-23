<?php

namespace Miraheze\MirahezeRequests\Specials;

use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

class SpecialRequestDPE extends SpecialRequest {
	public function __construct( MirahezeRequestsDatabaseService $dbService ) {
		parent::__construct( 'DPE', 'request-dpe', $dbService );
	}

	protected function getFormFields(): array {
		return [
			'wiki' => [
				'type' => 'text',
				'label-message' => 'requestdpe-wiki',
				'required' => true,
			],
			'reason' => [
				'type' => 'select',
				'label-message' => 'requestdpe-reason',
				'options-messages' => [
					'requestdpe-complete' => 'comp',
					'requestdpe-tbg' => 'tbg',
					'requestdpe-tbh' => 'temphardship',
					'requestdpe-other' => 'other',
				]
			],
			'explanation' => [
				'type' => 'textarea',
				'label-message' => 'requestdpe-explanation',
				'required' => true,
				'rows' => 6,
			],
		];
	}

	protected function getInsertRow( array $data, $timestamp ): array {
		return [
			'request_actor' => $this->getUser()->getActorId(),
			'request_timestamp' => $timestamp,
			'request_wiki' => $data['wiki'],
			'request_username' => $data['username'],
			'request_reason' => $data['reason'],
			'request_explanation' => $data['explanation'],
			'request_status' => self::STATUS_PENDING,
		];
	}
}
