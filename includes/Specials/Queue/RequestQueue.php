<?php

namespace Miraheze\MirahezeRequests\Specials\Queue;

use HTMLForm;
use MediaWiki\Html\Html;
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

	abstract protected function buildPager( array $filters, string $type );

	public function execute( $subPage ): void {
		$this->setHeaders();

		if ( $subPage ) {
			$this->getOutput()->addBacklinkSubtitle( $this->getPageTitle() );
			$this->lookupRequest( (int)$subPage );
			return;
		}

		$this->checkPermissions();
		$this->showFilterAndPager();
	}

	protected function lookupRequest( int $requestId ): void {
		$viewer = $this->makeViewer();
		$form = $viewer->getForm( $requestId );
		$form?->show();
	}

	protected function getType(): string {
		return $this->getRequest()->getVal( 'type' ) === 'closed' ? 'closed' : 'open';
	}

	protected function showFilterAndPager(): void {
		$type = $this->getType();

		$this->renderTabs( $type );

		$filters = $this->getFiltersFromRequest();
		$this->renderFilterForm( $filters + [ 'type' => $type ] );
		$this->renderPager( $filters, $type );
	}

	protected function renderTabs( string $type ): void {
		$linkRenderer = $this->getLinkRenderer();
		$title = $this->getPageTitle();

		$tabs = [
			'open' => $this->msg( 'mirahezerequests-tab-open' )->text(),
			'closed' => $this->msg( 'mirahezerequests-tab-closed' )->text(),
		];

		$links = [];
		foreach ( $tabs as $tabType => $label ) {
			$attribs = $tabType === $type
				? [ 'style' => 'font-weight:bold;border-bottom:2px solid #36c;padding-bottom:0.3em;' ]
				: [ 'style' => 'padding-bottom:0.3em;' ];

			$links[] = $linkRenderer->makeLink(
				$title, $label, $attribs, [ 'type' => $tabType ]
			);
		}

		$this->getOutput()->addHTML(
			Html::rawElement( 'div',
				[ 'style' => 'display:flex;gap:1.5em;margin-bottom:1em;border-bottom:1px solid #a2a9b1;' ],
				implode( '', $links )
			)
		);
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
		$descriptor['type'] = [
			'type' => 'hidden',
			'default' => $filters['type'],
		];

		$form = HTMLForm::factory( 'codex', $descriptor, $this->getContext() );
		$form->setMethod( 'get' )
			->setWrapperLegendMsg( $this->getName() )
			->setSubmitTextMsg( 'search' )
			->prepareForm()
			->displayForm( false );
	}

	protected function renderPager( array $filters, string $type ): void {
		$pager = $this->buildPager( $filters, $type );
		$table = $pager->getFullOutput();
		$parserOptions = ParserOptions::newFromContext( $this->getContext() );
		$this->getOutput()->addParserOutputContent( $table, $parserOptions );
	}
}
