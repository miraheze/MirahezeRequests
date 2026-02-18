<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\Extension\Notifications\AttributeManager;
use MediaWiki\Extension\Notifications\Hooks\BeforeCreateEchoEventHook;
use MediaWiki\Extension\Notifications\UserLocator;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\MirahezeRequests\Notifications\EchoNewRequestPresentationModel;
use Miraheze\MirahezeRequests\Notifications\EchoRenameWikiFailedPresentationModel;
use Miraheze\MirahezeRequests\Notifications\EchoRequestCommentPresentationModel;
use Miraheze\MirahezeRequests\Notifications\EchoRequestStatusUpdatePresentationModel;
use Wikimedia\Rdbms\IConnectionProvider;

class Notifications implements BeforeCreateEchoEventHook {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider
	) {
	}

	/** @inheritDoc */
	public function onBeforeCreateEchoEvent(
		array &$notifications,
		array &$notificationCategories,
		array &$icons
	): void {
		$dbr = $this->connectionProvider->getReplicaDatabase( 'virtual-mirahezerequests' );
		if ( !WikiMap::isCurrentWikiDbDomain( $dbr->getDomainID() ) ) {
			return;
		}

		$notificationCategories['renamewiki-renamewiki-failed'] = [
			'priority' => 1,
			'no-dismiss' => [ 'all' ],
		];

		$notificationCategories['renamewiki-new-request'] = [
			'priority' => 2,
			'no-dismiss' => [ 'all' ],
		];

		$notificationCategories['renamewiki-request-comment'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-renamewiki-request-comment',
		];

		$notificationCategories['renamewiki-request-status-update'] = [
			'priority' => 3,
			'no-dismiss' => [ 'email' ],
			'tooltip' => 'echo-pref-tooltip-renamewiki-request-status-update',
		];

		$notifications['renamewiki-renamewiki-failed'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'renamewiki-renamewiki-failed',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRenameWikiFailedPresentationModel::class,
			'immediate' => true,
		];

		$notifications['renamewiki-new-request'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'renamewiki-new-request',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoNewRequestPresentationModel::class,
			'immediate' => true,
		];

		$notifications['renamewiki-request-comment'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'renamewiki-request-comment',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestCommentPresentationModel::class,
			'immediate' => true,
		];

		$notifications['renamewiki-request-status-update'] = [
			AttributeManager::ATTR_LOCATORS => [
				[ [ UserLocator::class, 'locateEventAgent' ] ],
			],
			'category' => 'renamewiki-request-status-update',
			'group' => 'positive',
			'section' => 'alert',
			'canNotifyAgent' => true,
			'presentation-model' => EchoRequestStatusUpdatePresentationModel::class,
			'immediate' => true,
		];
	}
}
