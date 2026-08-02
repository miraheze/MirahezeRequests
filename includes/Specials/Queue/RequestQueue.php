<?php

namespace Miraheze\MirahezeRequests\Specials\Queue;

use HTMLForm;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Miraheze\MirahezeRequests\Specials\Viewer\RequestViewer;

abstract class RequestQueue extends SpecialPage implements MirahezeRequestsStatus {
	public function __construct(
		string $name,
		string $right,
		protected readonly MirahezeRequestsDatabaseService $dbService,
		protected readonly UserFactory $userFactory
	) {
		parent::__construct( $name, $right );
	}

	// Required hooks
	abstract protected function makeViewer(): RequestViewer;

	abstract protected function buildFilterFormDescriptor( array $filters ): array;

	abstract protected function buildPager( array $filters );

	public function execute( $subPage ): void {
		$this->setHeaders();

		if ( $subPage ) {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
			$this->lookupRequest( (int)$subPage );
			return;
		}

		$this->showFilterAndPager();
	}

	protected function lookupRequest( int $requestId ): void {
		$viewer = $this->makeViewer();
		$form = $viewer->getForm( $requestId );
		$form->show();
	}

	protected function showFilterAndPager(): void {
		$filters = $this->getFiltersFromRequest();
		$this->renderFilterForm( $filters );
		$this->renderPager( $filters );
	}

	protected function getFiltersFromRequest(): array {
		// override if needed
		return [
			'requester' => $this->getRequest()->getText( 'requester' ),
			'status' => $this->getRequest()->getText( 'status' ),
		];
	}

	protected function renderFilterForm( array $filters ): void {
		$descriptor = $this->buildFilterFormDescriptor( $filters );
		$form = HTMLForm::factory( 'codex', $descriptor, $this->getContext() );
		$form->setMethod( 'get' )
			->setWrapperLegendMsg( $this->getName() )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );
	}

	protected function renderPager( array $filters ): void {
		$pager = $this->buildPager( $filters );
		$table = $pager->getFullOutput();
		$parserOptions = ParserOptions::newFromContext( $this->getContext() );
		$this->getOutput()->addParserOutputContent( $table, $parserOptions );
	}
}
