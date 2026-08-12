<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\HTMLForm\HTMLForm;

abstract class RequestViewer {

	abstract public function getForm( int $requestId );

	abstract protected function getFormDescriptor(): array;

	abstract protected function submitForm( array $formData, HTMLForm $form );
}
