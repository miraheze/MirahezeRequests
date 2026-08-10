<?php

namespace Miraheze\MirahezeRequests;

/**
 * The possible states a request can be in.
 */
enum MirahezeRequestsStatus: string {

	case Complete = 'complete';

	case Declined = 'declined';

	case Failed = 'failed';

	case InProgress = 'inprogress';

	case Pending = 'pending';

	case Starting = 'starting';

	/**
	 * Statuses for requests that are still awaiting action or being
	 * actively worked on.
	 */
	public const array OPEN = [
		self::Pending->value,
		self::Starting->value,
		self::InProgress->value,
	];

	/**
	 * Statuses for requests that have reached a final outcome.
	 */
	public const array CLOSED = [
		self::Complete->value,
		self::Declined->value,
		self::Failed->value,
	];

	/**
	 * The i18n message key used to display this status to users.
	 */
	public function getMessageKey(): string {
		return 'mirahezerequests-status-' . $this->value;
	}
}
