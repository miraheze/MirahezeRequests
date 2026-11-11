<?php

namespace Miraheze\MirahezeRequests\Specials\Pager;

use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Wikimedia\Rdbms\IReadableDatabase;

abstract class RequestQueuePager extends TablePager {
	private IReadableDatabase $dbr;

	public function __construct(
		IContextSource $context,
		MirahezeRequestsDatabaseService $dbService,
		LinkRenderer $linkRenderer,
		protected readonly UserFactory $userFactory,
		protected readonly string $requester,
		protected readonly string $status,
		protected readonly string $type = 'open'
	) {
		parent::__construct( $context, $linkRenderer );
		$this->dbr = $dbService->getDbr();
	}

	abstract protected function getTableName(): string;

	abstract protected function getRequestFields(): array;

	abstract protected function getExtraConds(): array;

	abstract protected function getStatusPageName(): string;

	abstract protected function formatStatusLabel( string $status ): string;

	/**
	 * @param string $name
	 * @param mixed $value
	 * @return string HTML
	 */
	abstract protected function formatRowValue( string $name, $value ): string;

	/** @inheritDoc */
	public function getQueryInfo(): array {
		$info = [
			'tables' => [ $this->getTableName() ],
			'fields' => $this->getRequestFields(),
			'conds' => $this->getExtraConds(),
			'joins_conds' => [],
		];

		if ( $this->requester ) {
			$user = $this->userFactory->newFromName( $this->requester );
			$info['conds']['request_actor'] = $user ? $user->getActorId() : 0;
		}

		if ( $this->status && $this->status !== '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( $this->type === 'all' ) {
			$info['conds']['request_status'] = array_merge(
				MirahezeRequestsStatus::OPEN,
				MirahezeRequestsStatus::CLOSED
			);
		} else {
			$info['conds']['request_status'] = $this->type === 'closed'
				? MirahezeRequestsStatus::CLOSED
				: MirahezeRequestsStatus::OPEN;
		}

		return $info;
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		if ( $value === null ) {
			return '';
		}

		if ( $name === 'request_status' ) {
			$row = $this->getCurrentRow();
			return $this->getLinkRenderer()->makeLink(
				SpecialPage::getTitleValueFor( $this->getStatusPageName(), $row->request_id ),
				$this->formatStatusLabel( (string)$value )
			);
		}

		return $this->formatRowValue( $name, $value );
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'request_id';
	}

	/** @inheritDoc */
	public function isFieldSortable( $field ): bool {
		return $field !== 'request_actor';
	}
}
