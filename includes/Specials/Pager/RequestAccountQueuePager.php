<?php

namespace Miraheze\MirahezeRequests\Specials\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use stdClass;

class RequestAccountQueuePager extends RequestQueuePager {

	private array $actorNameCache = [];

	public function __construct(
		IContextSource $context,
		MirahezeRequestsDatabaseService $dbService,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		string $requester,
		string $status,
		string $type,
		private readonly string $username,
		private readonly string $email,
		private readonly bool $canSeeIp
	) {
		parent::__construct( $context, $dbService, $linkRenderer, $userFactory, $requester, $status, $type );
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		$fields = [
			'request_timestamp' => $this->msg( 'mirahezerequests-label-requested-date' )->text(),
			'request_username' => $this->msg( 'requestaccount-username-short' )->text(),
			'request_email' => $this->msg( 'requestaccount-email-short' )->text(),
			'request_actor' => $this->msg( 'mirahezerequests-label-requester' )->text(),
		];

		if ( $this->canSeeIp ) {
			$fields['request_ip'] = $this->msg( 'mirahezerequests-label-ip' )->text();
		}

		if ( $this->type === 'closed' ) {
			$fields['request_completed_timestamp'] = $this->msg( 'mirahezerequests-label-completed-date' )->text();
			$fields['request_completed_actor'] = $this->msg( 'mirahezerequests-label-donetby' )->text();
		}

		$fields['request_status'] = $this->msg( 'mirahezerequests-label-status' )->text();

		return $fields;
	}

	private function formatTimestamp( string $value ): string {
		return htmlspecialchars( $this->getLanguage()->userTimeAndDate(
			$value, $this->getUser()
		) );
	}

	private function formatActorName( int $actorId ): string {
		if ( !isset( $this->actorNameCache[$actorId] ) ) {
			$user = $this->userFactory->newFromActorId( $actorId );
			$this->actorNameCache[$actorId] = $user->getName();
		}

		return htmlspecialchars( $this->actorNameCache[$actorId] );
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		if ( $value === null && $name === 'request_actor' ) {
			return $this->formatRequester( $this->getCurrentRow() );
		}

		return parent::formatValue( $name, $value );
	}

	private function formatRequester( stdClass $row ): string {
		// Logged-out requests have no actor; show the IP if the viewer may see it.
		if ( $this->canSeeIp && $row->request_ip !== '' ) {
			return htmlspecialchars( $row->request_ip );
		}

		return $this->msg( 'mirahezerequests-requester-anonymous' )->escaped();
	}

	/** @inheritDoc */
	protected function formatRowValue( string $name, $value ): string {
		return match ( $name ) {
			'request_timestamp', 'request_completed_timestamp' => $this->formatTimestamp( $value ),
			'request_username', 'request_email', 'request_ip' => htmlspecialchars( $value ),
			'request_actor', 'request_completed_actor' => $this->formatActorName( (int)$value ),
			default => "Unable to format $name",
		};
	}

	/** @inheritDoc */
	protected function formatStatusLabel( string $status ): string {
		$msgKey = "mirahezerequests-status-$status";
		if ( $this->msg( $msgKey )->exists() ) {
			return $this->msg( $msgKey )->text();
		}

		// Fallback only reached if the message above is missing; not
		// translated on purpose, since that indicates a broken i18n setup.
		return match ( $status ) {
			MirahezeRequestsStatus::Pending->value => 'Pending',
			MirahezeRequestsStatus::Starting->value => 'Starting',
			MirahezeRequestsStatus::Sending->value => 'Sending email',
			MirahezeRequestsStatus::MailFailed->value => 'Email failed',
			MirahezeRequestsStatus::Complete->value => 'Complete',
			MirahezeRequestsStatus::Declined->value => 'Declined',
			default => $status,
		};
	}

	/** @inheritDoc */
	protected function getTableName(): string {
		return 'account_requests';
	}

	/** @inheritDoc */
	protected function getRequestFields(): array {
		$fields = [
			'request_actor',
			'request_id',
			'request_status',
			'request_timestamp',
			'request_username',
			'request_email',
		];

		if ( $this->canSeeIp ) {
			$fields[] = 'request_ip';
		}

		if ( $this->type === 'closed' ) {
			$fields[] = 'request_completed_timestamp';
			$fields[] = 'request_completed_actor';
		}

		return $fields;
	}

	/** @inheritDoc */
	protected function getExtraConds(): array {
		$conds = [];

		if ( $this->username !== '' ) {
			$conds['request_username'] = $this->username;
		}

		if ( $this->email ) {
			$conds['request_email'] = $this->email;
		}

		return $conds;
	}

	/** @inheritDoc */
	protected function getStatusPageName(): string {
		return 'RequestAccountQueue';
	}
}
