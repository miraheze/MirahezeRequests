<?php

namespace Miraheze\MirahezeRequests;

/**
 * The possible states a request can be in.
 */
enum MirahezeRequestsStatus: string {

	case Complete = 'complete';

	case Declined = 'declined';

	case Pending = 'pending';

	case Starting = 'starting';

	case MailFailed = 'mailfailed';

	case Sending = 'sending';

	/** Waiting for the requester to confirm their email address; not in the queue yet. */
	case Unconfirmed = 'unconfirmed';

	/**
	 * Statuses for requests that are still awaiting action or being
	 * actively worked on.
	 */
	public const array OPEN = [
		self::Pending->value,
		self::Starting->value,
		self::MailFailed->value,
		self::Sending->value,
	];

	/**
	 * Statuses for requests that have reached a final outcome.
	 */
	public const array CLOSED = [
		self::Complete->value,
		self::Declined->value,
	];

	/**
	 * The i18n message key used to display this status to users.
	 */
	public function getMessageKey(): string {
		return 'mirahezerequests-status-' . $this->value;
	}

	/**
	 * Statuses handlers see in the queue.
	 *
	 * @return self[]
	 */
	public static function queueCases(): array {
		return array_filter( self::cases(), static fn ( self $case ) => $case !== self::Unconfirmed );
	}
}
