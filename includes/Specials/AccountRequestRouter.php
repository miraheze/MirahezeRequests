<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Extension\CentralAuth\SharedDomainUtils;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\WikiMap\WikiMap;
use RuntimeException;

/** Keeps authentication on the shared domain and the queue on the wiki. */
class AccountRequestRouter {

	public const AUTH_QUERY = [ 'usesul3' => '1' ];

	public function __construct(
		private readonly ?SharedDomainUtils $sharedDomainUtils,
	) {
	}

	/**
	 * @param SpecialPage $page
	 * @param ?string $par
	 * @return bool False if the request was redirected and the page must not render
	 * @throws ErrorPageError On POST outside the authentication domain
	 */
	public function ensureAuthenticationDomain( SpecialPage $page, ?string $par ): bool {
		$request = $page->getRequest();
		if (
			!$this->sharedDomainUtils?->canSul3BeEnabled() ||
			( $this->sharedDomainUtils->isSharedDomain() && $this->sharedDomainUtils->isSul3Enabled( $request ) )
		) {
			return true;
		}

		if ( $request->wasPosted() ) {
			throw new ErrorPageError( 'requestaccount', 'sessionfailure' );
		}

		$query = $request->getQueryValuesOnly();
		unset( $query['title'] );
		$title = $page->getPageTitle( $par === null || $par === '' ? false : $par );
		$query = self::AUTH_QUERY + $query;
		// On the shared domain, the script path already includes the wiki prefix.
		$url = $this->sharedDomainUtils->isSharedDomain() ? $title->getFullURL() :
			$this->sharedDomainUtils->getSharedDomainPrefix() . $title->getLocalURL();
		$page->getOutput()->redirect( wfAppendQuery( $url, $query ) );

		return false;
	}

	/**
	 * @param SpecialPage $page
	 * @return bool False if the request was redirected and the page must not render
	 * @throws ErrorPageError On POST to the queue on the authentication domain
	 */
	public function ensureQueueDomain( SpecialPage $page ): bool {
		if ( !$this->sharedDomainUtils?->isSharedDomain() ) {
			return true;
		}

		if ( $page->getRequest()->wasPosted() ) {
			throw new ErrorPageError( 'requestaccount', 'sessionfailure' );
		}

		$query = $page->getRequest()->getQueryValuesOnly();
		unset( $query['title'], $query['usesul3'] );
		$page->getOutput()->redirect(
			$this->getQueueURL( $query )
		);

		return false;
	}

	public function getQueueURL( array $query ): string {
		$title = SpecialPage::getTitleFor( 'RequestAccountQueue' );
		if ( !$this->sharedDomainUtils?->isSharedDomain() ) {
			return $title->getCanonicalURL( $query );
		}

		// The current CanonicalServer points at auth, so use the wiki's site configuration.
		$wiki = WikiMap::getWiki( WikiMap::getCurrentWikiId() );
		if ( !$wiki ) {
			throw new RuntimeException( 'The request queue wiki must be registered in WikiMap.' );
		}
		return wfAppendQuery( $wiki->getCanonicalUrl( $title->getPrefixedText() ), $query );
	}
}
