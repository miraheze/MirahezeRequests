<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\Requests\AccountCreator;

/** Makes pages that check or create accounts use every auth provider. */
class FullProvidersGuard {

	/**
	 * @param SpecialPage $page
	 * @param ExtensionRegistry $extensionRegistry
	 * @param ?string $par
	 * @return bool False if the request was redirected and the page must not render
	 * @throws ErrorPageError On POST without it, which must not skip checks
	 */
	public static function ensure( SpecialPage $page, ExtensionRegistry $extensionRegistry, ?string $par ): bool {
		$request = $page->getRequest();
		if (
			!$extensionRegistry->isLoaded( 'CentralAuth' ) ||
			$request->getRawVal( 'usesul3' ) === AccountCreator::FULL_PROVIDERS_QUERY['usesul3']
		) {
			return true;
		}

		if ( $request->wasPosted() ) {
			throw new ErrorPageError( 'requestaccount', 'sessionfailure' );
		}

		$query = $request->getQueryValuesOnly();
		unset( $query['title'] );
		$page->getOutput()->redirect(
			$page->getPageTitle( $par )->getFullURL( AccountCreator::FULL_PROVIDERS_QUERY + $query )
		);

		return false;
	}
}
