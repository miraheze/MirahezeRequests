<?php

/*namespace Miraheze\MirahezeRequests;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\CodexHTMLForm;

class CodexHTMLFormTabs extends CodexHTMLForm {
	public function getBody(): string {
		$tabs = [];

		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				continue;
			}

			$label = $this->getLegend( $key );

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection( $val, '', "mw-section-$key-" ) .
				$this->getFooterHtml( $key );

			$tabs[] = [
				'name' => $key,
				'label' => $label,
				'html' => $content
			];
		}

		$out = $this->getOutput();
		$out->addModules( 'ext.mirahezerequests.codex.tabs' );

		$header = '';

		return Html::rawElement( 'div', [
			'id' => 'ext-renamewiki-tabs-root',
			'data-tabs' => json_encode( $tabs )
		], '' );
	}
}*/

namespace Miraheze\MirahezeRequests;

use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\CodexHTMLForm;

class CodexHTMLFormTabs extends CodexHTMLForm {
	public function getBody(): string {
		$tabs = [];

		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				continue;
			}

			$label = $this->msg( "$this->mMessagePrefix-$key" )->text();

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection( $val, '', "mw-section-$key-" ) .
				$this->getFooterHtml( $key );

			$tabs[] = [
				'name' => $key,
				'label' => $label,
				'html' => $content,
			];
		}

		$this->getOutput()->addModules( 'ext.mirahezerequests.tabs' );

		return Html::element('div', [
			'id' => 'mirahezerequests-tabs-root',
			'data-tabs' => json_encode( $tabs ),
		] );
	}
}
