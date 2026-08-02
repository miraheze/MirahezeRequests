<?php

namespace Miraheze\MirahezeRequests\Specials\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

/* this is rough and very very badly needs factories and proper methods */
class RequestAccountQueuePager extends RequestQueuePager {

	private array $actorNameCache = [];
	private string $username;
	private string $email;
	private bool $canSeeIp;

	public function __construct(
		IContextSource $context,
		MirahezeRequestsDatabaseService $dbService,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		string $requester,
		string $status,
		string $type,
		string $username,
		string $email,
		bool $canSeeIp

	) {
		$this->username = $username;
		$this->email = $email;
		$this->canSeeIp = $canSeeIp;
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

		$fields['request_status'] = $this->msg( 'status' )->text();

		return $fields;
	}

	private function formatTimestamp( $value ): string {
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

	protected function formatRowValue( string $name, $value ): string {
		return match ( $name ) {
			'request_timestamp', 'request_completed_timestamp' => $this->formatTimestamp( $value ),
			'request_username', 'request_email', 'request_ip' => htmlspecialchars( $value ),
			'request_actor', 'request_completed_actor' => $this->formatActorName( (int)$value ),
			default => "Unable to format $name",
		};
	}

	protected function formatStatusLabel( string $status ): string {
		$msgKey = "mirahezerequests-status-$status";
		if ( $this->msg( $msgKey )->exists() ) {
			return $this->msg( $msgKey )->text();
		}

		return match ( $status ) {
			self::STATUS_PENDING => 'Pending',
			self::STATUS_STARTING => 'Starting',
			self::STATUS_INPROGRESS => 'In progress',
			self::STATUS_COMPLETE => 'Complete',
			self::STATUS_DECLINED => 'Declined',
			self::STATUS_FAILED => 'Failed',
			default => $status,
		};
	}

	protected function getTableName(): string {
		return 'account_requests';
	}

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

	protected function getExtraConds(): array {
		$conds = [];

		if ( $this->username ) {
			$conds['request_username'] = $this->username;
		}

		if ( $this->email ) {
			$conds['request_email'] = $this->email;
		}

		return $conds;
	}

	protected function getStatusPageName(): string {
		return 'RequestAccountQueue';
	}
}
