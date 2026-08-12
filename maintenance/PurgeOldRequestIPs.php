<?php

namespace Miraheze\MirahezeRequests\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;
use Miraheze\MirahezeRequests\ConfigNames;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class PurgeOldRequestIPs extends Maintenance {

	private const TABLE = 'account_requests';

	public function __construct() {
		parent::__construct();

		$this->addDescription(
			'Purges requester IP addresses from account requests older than the configured ' .
			'retention period ($wgMirahezeRequestsIPRetentionDays). The rest of the request ' .
			'(username, email, reason, status, etc.) is left untouched; only request_ip is cleared. ' .
			'Intended to be run periodically, e.g. via cron.'
		);

		$this->addOption( 'dry-run', 'Report how many rows would be affected without changing anything.' );
		$this->addOption( 'retention-days', 'Override the configured retention period, in days.', false, true );

		$this->requireExtension( 'MirahezeRequests' );
	}

	public function execute(): void {
		$services = MediaWikiServices::getInstance();
		$config = $services->get( 'MirahezeRequestsConfig' );
		$dbService = $services->get( 'MirahezeRequestsDatabaseService' );

		$retentionDays = $this->hasOption( 'retention-days' )
			? (int)$this->getOption( 'retention-days' )
			: (int)$config->get( ConfigNames::IPRetentionDays );

		if ( $retentionDays <= 0 ) {
			$this->output( "IP retention purging is disabled (retention days <= 0).\n" );
			return;
		}

		$dbw = $dbService->getDbw();
		$cutoff = $dbw->timestamp( time() - ( $retentionDays * 86400 ) );

		$conds = [
			$dbw->expr( 'request_timestamp', '<', $cutoff ),
			$dbw->expr( 'request_ip', '!=', '' ),
		];

		$rowCount = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( self::TABLE )
			->where( $conds )
			->caller( __METHOD__ )
			->fetchField();

		if ( !$rowCount ) {
			$this->output( "No account requests older than $retentionDays days have IPs to purge.\n" );
			return;
		}

		if ( $this->hasOption( 'dry-run' ) ) {
			$this->output( "Would purge IPs from $rowCount account request(s) older than $retentionDays days.\n" );
			return;
		}

		$this->output( "Purging IPs from $rowCount account request(s) older than $retentionDays days...\n" );

		$dbw->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'request_ip' => '' ] )
			->where( $conds )
			->caller( __METHOD__ )
			->execute();

		$this->output( "Done. Purged IPs from $rowCount account request(s).\n" );
	}
}

$maintClass = PurgeOldRequestIPs::class;
require_once RUN_MAINTENANCE_IF_MAIN;
