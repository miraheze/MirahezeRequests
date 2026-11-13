<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\SpecialPage\FormSpecialPage;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

abstract class SpecialRequest extends FormSpecialPage {
	public function __construct(
		readonly string $name,
		private readonly string $right,
		protected readonly MirahezeRequestsDatabaseService $dbService
	) {
		parent::__construct( 'Request' . $name );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return $this->right;
	}

	/**
	 * The HTMLForm field descriptors for the request form.
	 */
	abstract protected function getFormFields(): array;

	/**
	 * Build the database row to insert for a newly-submitted request,
	 * from the validated form data and the request's timestamp.
	 */
	abstract protected function getInsertRow( array $data, string $timestamp ): array;

	/** @inheritDoc */
	public function execute( $par ): void {
		$this->setParameter( $par );
		$this->setHeaders();

		$this->dbService->assertCentralWiki();
		// also checks blocks and read-only mode, unlike checkPermissions()
		$this->checkExecutePermissions( $this->getUser() );

		if ( $this->getForm()->show() ) {
			$this->onSuccess();
		}
	}

	/** @inheritDoc */
	protected function getDisplayFormat(): string {
		return 'codex';
	}

	/** @inheritDoc */
	public function doesWrites(): bool {
		return true;
	}
}
