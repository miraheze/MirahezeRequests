<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Tests\Integration;

use MediaWiki\Context\RequestContext;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Tests\Unit\HtmlAssertionHelperTrait;
use MediaWikiIntegrationTestCase;
use Miraheze\MirahezeRequests\OOUIHTMLFormTabs;
use Wikimedia\Parsoid\Core\DOMCompat;
use Wikimedia\Parsoid\Ext\DOMUtils;

/** @covers \Miraheze\MirahezeRequests\OOUIHTMLFormTabs */
class OOUIHTMLFormTabsTest extends MediaWikiIntegrationTestCase {
	use HtmlAssertionHelperTrait;

	private function newForm(): OOUIHTMLFormTabs {
		$context = new RequestContext();
		$context->setTitle( SpecialPage::getTitleFor( 'RequestAccountQueue', '123' ) );
		return ( new OOUIHTMLFormTabs( [
			'username' => [
				'type' => 'text',
				'section' => 'details',
				'default' => 'Requested user',
			],
			'submit-accept' => [
				'type' => 'submit',
				'section' => 'handling',
				'buttonlabel-message' => 'mirahezerequests-label-accept',
			],
		], $context, 'requestaccount-section' ) )->prepareForm();
	}

	public function testSectionsRenderWithoutJavaScriptAndSupportInfusion(): void {
		$html = $this->newForm()->getHTML( false );
		$document = DOMUtils::parseHTML( $html );
		$tabs = $this->assertSelectorMatchesOneElementInNode(
			$document, '.oo-ui-indexLayout[data-ooui][data-mw-modules="oojs-ui-widgets"]'
		);
		$panels = DOMCompat::querySelectorAll( $tabs, '[role="tabpanel"]' );
		$this->assertCount( 2, $panels );
		$this->assertSelectorMatchesOneElementInNode(
			$panels[0], 'input[name="wpusername"][value="Requested user"]'
		);
		$this->assertSelectorMatchesOneElementInNode( $panels[1], 'button[name="wpsubmit-accept"]' );

		$labels = DOMCompat::querySelectorAll( $tabs, '[role="tab"]' );
		$this->assertCount( 2, $labels );
		$this->assertSame( wfMessage( 'requestaccount-section-details' )->text(), $labels[0]->textContent );
		$this->assertSame( wfMessage( 'requestaccount-section-handling' )->text(), $labels[1]->textContent );
	}

	public function testSubmissionErrorsRemainVisibleAboveTheTabs(): void {
		$html = $this->newForm()->getHTML( Status::newFatal( 'mirahezerequests-status-conflict' ) );
		$header = $this->assertSelectorMatchesOneElement(
			$html, '.mw-htmlform-ooui-header-errors'
		);
		$this->assertStringContainsString( wfMessage( 'mirahezerequests-status-conflict' )->parse(), $header );
		$this->assertSelectorMatchesOneElement( $html, '.mw-htmlform-ooui-header-errors ~ .oo-ui-indexLayout' );
	}
}
