<?php

namespace Miraheze\MirahezeRequests\Hooks\Handlers;

use MediaWiki\User\Hook\UserGetReservedNamesHook;

class Main implements UserGetReservedNamesHook {

	public function onUserGetReservedNames( &$reservedUsernames ) {
		$reservedUsernames[] = 'MirahezeRequests';
	}
}
