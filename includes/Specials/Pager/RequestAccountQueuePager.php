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

	public function __construct(
		IContextSource $context,
		MirahezeRequestsDatabaseService $dbService,
		LinkRenderer $linkRenderer,
		UserFactory $userFactory,
		string $requester,
		string $status,
		string $username,
		string $email

	) {
		parent::__construct( $context, $dbService, $linkRenderer, $userFactory, $requester, $status );
		$this->username = $username;
		$this->email = $email;
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'request_timestamp' => $this->msg( 'mirahezerequests-label-requested-date' )->text(),
			'request_username' => $this->msg( 'requestaccount-username-short' )->text(),
			'request_email' => $this->msg( 'requestaccount-email-short' )->text(),
			'request_actor' => $this->msg( 'mirahezerequests-label-requester' )->text(),
			'request_status' => $this->msg( 'status' )->text(),
		];
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
			'request_timestamp' => $this->formatTimestamp( $value ),
			'request_username', 'request_email' => htmlspecialchars( $value ),
			'request_actor' => $this->formatActorName( (int)$value ),
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
		return [
			'request_actor',
			'request_id',
			'request_status',
			'request_timestamp',
			'request_username',
			'request_email',
		];
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
