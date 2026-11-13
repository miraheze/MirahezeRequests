<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Status\Status;

abstract class RequestViewer {

	/**
	 * @param int $requestId
	 * @return HTMLForm
	 */
	abstract public function getForm( int $requestId );

	abstract protected function getFormDescriptor(): array;

	/**
	 * @param array $formData
	 * @param HTMLForm $form
	 * @return bool|Status
	 */
	abstract protected function submitForm( array $formData, HTMLForm $form );
}
