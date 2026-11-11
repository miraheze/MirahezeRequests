<?php

namespace Miraheze\MirahezeRequests\Specials\Queue;

use MediaWiki\Exception\UserBlockedError;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Miraheze\MirahezeRequests\Specials\Pager\RequestQueuePager;
use Miraheze\MirahezeRequests\Specials\Viewer\RequestViewer;

abstract class RequestQueue extends SpecialPage {
	public function __construct(
		string $name,
		private readonly string $right,
		protected readonly MirahezeRequestsDatabaseService $dbService,
		protected readonly UserFactory $userFactory
	) {
		parent::__construct( $name );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return $this->right;
	}

	// Required hooks
	abstract protected function makeViewer(): RequestViewer;

	abstract protected function buildFilterFormDescriptor( array $filters ): array;

	/**
	 * @param array $filters
	 * @param string $type open or closed
	 * @return RequestQueuePager
	 */
	abstract protected function buildPager( array $filters, string $type );

	/**
	 * @inheritDoc
	 * @throws UserBlockedError
	 */
	public function execute( $subPage ): void {
		$this->setHeaders();

		$this->dbService->assertCentralWiki();

		// Blocked requesters may still view their own request; only the
		// queue list and handling POSTs are limited to unblocked users.
		if ( !$subPage || $this->getRequest()->wasPosted() ) {
			$block = $this->getUser()->getBlock();
			if ( $block && $block->isSitewide() ) {
				throw new UserBlockedError( $block );
			}
		}

		// viewing still works while read-only
		if ( $this->getRequest()->wasPosted() ) {
			$this->checkReadOnly();
		}

		if ( $subPage ) {
			// the viewer lets requesters see their own request too
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
		$type = $this->getRequest()->getVal( 'type' );
		return in_array( $type, [ 'open', 'closed', 'all' ], true ) ? $type : 'open';
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
			// HTMLForm prefixes unnamed fields with "wp", but the page reads "type"
			'name' => 'type',
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
