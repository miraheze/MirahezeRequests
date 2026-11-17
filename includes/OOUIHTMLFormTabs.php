<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests;

use MediaWiki\HTMLForm\OOUIHTMLForm;
use OOUI\HtmlSnippet;
use OOUI\IndexLayout;
use OOUI\TabPanelLayout;

class OOUIHTMLFormTabs extends OOUIHTMLForm {
	/** @inheritDoc */
	public function getBody(): string {
		$tabPanels = [];

		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				continue;
			}

			$label = $this->getLegend( $key );

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection( $val, '', "mw-section-$key-" ) .
				$this->getFooterHtml( $key );

			$tabPanels[] = new TabPanelLayout( $key, [
				'label' => $label,
				'content' => new HtmlSnippet( $content ),
				'expanded' => false,
				'padded' => true,
			] );
		}

		$this->getOutput()->addModules( 'oojs-ui-widgets' );
		$this->getOutput()->addModuleStyles( 'oojs-ui-widgets.styles' );

		$tabs = new IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'autoFocus' => false,
			'classes' => [ 'mw-htmlform-autoinfuse' ],
		] );
		$tabs->setAttributes( [ 'data-mw-modules' => 'oojs-ui-widgets' ] );
		$tabs->addTabPanels( $tabPanels );

		return $this->formatFormHeader() . $tabs;
	}
}
