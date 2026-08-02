<?php

namespace Miraheze\MirahezeRequests\Services;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MessageLocalizer;
use Miraheze\MirahezeRequests\ConfigNames;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;

class MirahezeRequestsValidator {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::DatabaseSuffix,
		MainConfigNames::LocalDatabases
	];

	public function __construct(
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	private function validateSuffix( string $wiki ): Message|true {
		$suffix = $this->options->get( ConfigNames::DatabaseSuffix );

		if ( !str_ends_with( $wiki, $suffix ) ) {
			return $this->messageLocalizer->msg( 'renamewiki-invalid-database-suffix', [ $suffix ] );
		}

		return true;
	}

	private function validateDatabase( string $wiki, bool $mustExist ): Message|true {
		$this->validateSuffix( $wiki );

		$exists = in_array( $wiki, $this->options->get( MainConfigNames::LocalDatabases ), true );

		if ( $mustExist && !$exists ) {
			return $this->messageLocalizer->msg( 'renamewiki-invalid-source' );
		}

		if ( !$mustExist && $exists ) {
			return $this->messageLocalizer->msg( 'renamewiki-invalid-target' );
		}

		return true;
	}

	public function isValidDatabase( ?string $wiki ): Message|true {
		return $this->validateDatabase( $wiki, true );
	}

	public function isInvalidDatabase( ?string $wiki ): Message|true {
		return $this->validateDatabase( $wiki, false );
	}

	public function isValidComment( ?string $comment, array $alldata ): Message|true {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		return true;
	}

	public function isValidReason( ?string $reason ): Message|true {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->messageLocalizer->msg( 'htmlform-required' );
		}

		return true;
	}

	public function isValidStatus( ?string $status ): bool {
		if ( $status === MirahezeRequestsStatus::STATUS_COMPLETE ) {
			return false;
		}
		return true;
	}
}
