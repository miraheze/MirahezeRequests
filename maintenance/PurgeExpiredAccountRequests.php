<?php

namespace Miraheze\MirahezeRequests\Maintenance;

use MediaWiki\Maintenance\Maintenance;

class PurgeExpiredAccountRequests extends Maintenance {

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Delete account requests whose email address was not confirmed in time, and clear IP addresses ' .
			'of requests older than $wgMirahezeRequestsIPRetentionDays, counted from handling or from ' .
			'submission while the request is still open.'
		);

		$this->requireExtension( 'MirahezeRequests' );
	}

	/** @inheritDoc */
	public function execute(): void {
		/** @var \Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService $dbService */
		$dbService = $this->getServiceContainer()->get( 'MirahezeRequestsDatabaseService' );

		// actor IDs are only valid on the central wiki
		if ( !$dbService->isCentralWiki() ) {
			$this->fatalError( 'This script must be run on the wiki hosting the request tables.' );
		}

		/** @var \Miraheze\MirahezeRequests\Requests\RequestAccountManager $requestManager */
		$requestManager = $this->getServiceContainer()->get( 'RequestAccountManager' );

		$requestManager->purgeExpiredData();
		$this->output( "Done.\n" );
	}
}

// @codeCoverageIgnoreStart
return PurgeExpiredAccountRequests::class;
// @codeCoverageIgnoreEnd
