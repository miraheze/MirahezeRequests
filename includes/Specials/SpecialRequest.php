<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

abstract class SpecialRequest extends FormSpecialPage {
	private string $tableName;
	private int $insertedId = 0;

	public function __construct(
		readonly string $name,
		string $right,
		protected readonly MirahezeRequestsDatabaseService $dbService
	) {
		$pageName = 'Request' . $name;
		$this->tableName = strtolower( $name ) . '_requests';
		parent::__construct( $pageName, $right );
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

	public function execute( $par ): void {
		$this->setParameter( $par );
		$this->setHeaders();

		$this->dbService->isCentralDB();
		$this->checkPermissions();

		if ( $this->getForm()->show() ) {
			$this->onSuccess();
		}
	}

	public function onSubmit( array $data ): Status {
		// HTMLForm already checks the edit token before calling this.
		$dbw = $this->dbService->getDbw();
		$timestamp = $dbw->timestamp();

		$dbw->newInsertQueryBuilder()
			->insertInto( $this->tableName )
			->ignore()
			->row( $this->getInsertRow( $data, $timestamp ) )
			->caller( __METHOD__ )
			->execute();

		$this->insertedId = $dbw->insertId();

		return Status::newGood();
	}

	public function onSuccess(): void {
		$requestLink = $this->getLinkRenderer()->makeLink(
			SpecialPage::getTitleFor( 'Request' . $this->name . 'Queue', (string)$this->insertedId ),
			(string)$this->insertedId
		);

		$this->getOutput()->addHTML(
			Html::successBox(
				$this->msg( 'mirahezerequests-success' )->rawParams( $requestLink )->parse()
			)
		);
	}

	protected function getDisplayFormat(): string {
		return 'codex';
	}

	public function doesWrites(): bool {
		return true;
	}
}
