<?php

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

		return Html::element( 'div', [
			'id' => 'mirahezerequests-tabs-root',
			'data-tabs' => json_encode( $tabs ),
		] );
	}
}
