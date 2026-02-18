<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Context\IContextSource;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Pager\TablePager;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Wikimedia\Rdbms\IConnectionProvider;

class RenameWikiRequestQueuePager extends TablePager
	implements MirahezeRequestsStatus {

	public function __construct(
		IContextSource $context,
		IConnectionProvider $connectionProvider,
		LinkRenderer $linkRenderer,
		private readonly UserFactory $userFactory,
		private readonly string $requester,
		private readonly string $status,
		private readonly string $newwiki,
		private readonly string $oldwiki

	) {
		parent::__construct( $context, $linkRenderer );
		$this->mDb = $connectionProvider->getReplicaDatabase( 'virtual-mirahezerequests' );
	}

	/** @inheritDoc */
	protected function getFieldNames(): array {
		return [
			'request_timestamp' => $this->msg( 'renamewiki-table-requested-date' )->text(),
			'request_actor' => $this->msg( 'renamewiki-table-requester' )->text(),
			'request_status' => $this->msg( 'renamewiki-table-status' )->text(),
			'request_oldwiki' => $this->msg( 'renamewiki-table-oldwiki' )->text(),
			'request_newwiki' => $this->msg( 'renamewiki-table-newwiki' )->text(),
		];
	}

	/** @inheritDoc */
	public function formatValue( $name, $value ): string {
		if ( $value === null ) {
			return '';
		}

		switch ( $name ) {
			case 'request_timestamp':
				$formatted = htmlspecialchars( $this->getLanguage()->userTimeAndDate(
					$value, $this->getUser()
				) );
				break;
			case 'request_newwiki':
				$formatted = htmlspecialchars( $value );
				break;
			case 'request_status':
				$row = $this->getCurrentRow();
				$formatted = $this->getLinkRenderer()->makeLink(
					SpecialPage::getTitleValueFor( 'RequestRenameWikiQueue', $row->request_id ),
					$this->msg( "renamewiki-label-$value" )->text()
				);
				break;
			case 'request_actor':
				$user = $this->userFactory->newFromActorId( (int)$value );
				$formatted = htmlspecialchars( $user->getName() );
				break;
			default:
				$formatted = "Unable to format $name";
		}

		return $formatted;
	}

	/** @inheritDoc */
	public function getQueryInfo(): array {
		$info = [
			'tables' => [
				'renamewiki_requests',
			],
			'fields' => [
				'request_actor',
				'request_id',
				'request_status',
				'request_timestamp',
				'request_oldwiki',
				'request_newwiki',
			],
			'conds' => [],
			'joins_conds' => [],
		];

		if ( $this->newwiki ) {
			$info['conds']['request_newwiki'] = $this->newwiki;
		}

		if ( $this->oldwiki ) {
			$info['conds']['request_oldwiki'] = $this->oldwiki;
		}

		if ( $this->requester ) {
			$user = $this->userFactory->newFromName( $this->requester );
			$info['conds']['request_actor'] = $user->getActorId();
		}

		if ( $this->status && $this->status !== '*' ) {
			$info['conds']['request_status'] = $this->status;
		} elseif ( !$this->status ) {
			$info['conds']['request_status'] = self::STATUS_PENDING;
		}

		return $info;
	}

	/** @inheritDoc */
	public function getDefaultSort(): string {
		return 'request_id';
	}

	/** @inheritDoc */
	protected function isFieldSortable( $field ): bool {
		return $field !== 'request_actor';
	}
}
