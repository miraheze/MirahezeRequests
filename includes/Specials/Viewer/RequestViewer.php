<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\HTMLForm\HTMLForm;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;

abstract class RequestViewer implements MirahezeRequestsStatus {
	/*abstract protected function getFormDescriptor(): array;
	abstract protected function getFormId(): string;
	abstract protected function getRequestManager();
	abstract protected function buildForm( array $descriptor );

	public function getForm( int $requestId ) {
		$this->getRequestManager()->getById( $requestId );

		$descriptor = $this->getFormDescriptor();
		if ( !$descriptor ) {
			return null;
		}

		$form = $this->buildForm( $descriptor );
		$form->setId( $this->getFormId() );
		$form->suppressDefaultSubmit();
		$form->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		return $form;
	}*/
	abstract public function getForm( int $requestId );

	abstract protected function getFormDescriptor(): array;

	abstract protected function submitForm( array $formData, HTMLForm $form );
}
