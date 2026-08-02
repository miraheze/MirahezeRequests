<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\FormSpecialPage;
use MediaWiki\Status\Status;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;

abstract class SpecialRequest extends FormSpecialPage implements MirahezeRequestsStatus {
	private string $tableName;

	public function __construct(
		readonly string $name,
		string $right,
		protected readonly MirahezeRequestsDatabaseService $dbService
	) {
		$pageName = 'Request' . $name;
		$this->tableName = strtolower( $name ) . '_requests';
		parent::__construct( $pageName, $right );
	}

	abstract protected function getFormFields(): array;

	// abstract protected function getRequestTable(): string;
	abstract protected function getInsertRow( array $data, $timestamp ): array;

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
		$token = $this->getRequest()->getVal( 'wpEditToken' );
		$userToken = $this->getContext()->getCsrfTokenSet();
		if ( !$userToken->matchToken( $token ) ) {
			return Status::newFatal( 'sessionfailure' );
		}

		// Throttle if requested
		/*if ( $this->getLimiterKey() && $this->getUser()->pingLimiter( $this->getLimiterKey() ) ) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		// Allow request-specific validation
		$status = $this->beforeInsert( $data );
		if ( !$status->isOK() ) {
			return $status;
		}*/

		$dbw = $this->dbService->getDbw();
		$timestamp = $dbw->timestamp();

		$dbw->newInsertQueryBuilder()
			->insertInto( $this->tableName )
			->ignore()
			->row( $this->getInsertRow( $data, $timestamp ) )
			->caller( __METHOD__ )
			->execute();

		$this->getOutput()->addHTML(
			Html::successBox( $this->msg( 'mirahezerequests-success' ) )
		);
		return Status::newGood();
	}

	protected function getDisplayFormat(): string {
		return 'codex';
	}

	public function doesWrites(): bool {
		return true;
	}
}
