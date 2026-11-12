<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\Status\StatusFormatter;
use MediaWiki\Title\Title;
use Miraheze\MirahezeRequests\CodexHTMLFormTabs;
use Miraheze\MirahezeRequests\FieldValidator;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
use Miraheze\MirahezeRequests\Requests\AccountCreator;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Requests\UsernameValidator;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use Wikimedia\Codex\Utility\Codex;

class RequestAccountViewer extends RequestViewer {

	private const SAME_EMAIL_LIMIT = 10;

	public function __construct(
		private readonly IContextSource $context,
		private readonly LinkRenderer $linkRenderer,
		private readonly AuthManager $authManager,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly StatusFormatter $statusFormatter,
		private readonly AccountCreator $accountCreator,
		private readonly AccountRequestMailer $requestMailer,
		private readonly RequestAccountManager $requestManager,
		private readonly MirahezeRequestsDatabaseService $dbService,
	) {
	}

	/** @inheritDoc */
	public function getFormDescriptor(): array {
		if ( !$this->requestManager->isInQueue() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'mirahezerequests-notfound' )->escaped() )
			);
			return [];
		}

		// Handlers can view and handle any request, including their own.
		// Other requesters can view their own read-only - otherwise the
		// "request filed" success link 403s.
		$isHandler = $this->isHandler();
		$requester = $this->requestManager->getRequester();
		$isOwner = $requester && $this->context->getUser()->equals( $requester );

		if ( !$isHandler && !$isOwner ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'mirahezerequests-nopermission' )->escaped() )
			);
			return [];
		}

		$formDescriptor = [
			'username' => [
				'label-message' => 'requestaccount-username',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getUsername(),
			],
			'email' => [
				'label-message' => 'requestaccount-email',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getEmail(),
			],
		];

		// other requests are none of the requester's business
		if ( $isHandler ) {
			$formDescriptor['same-email'] = [
				'label-message' => 'requestaccount-label-same-email',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->formatRequestsWithSameEmail(),
				'raw' => true,
			];
		}

		if ( $requester ) {
			$formDescriptor['requester'] = [
				'label-message' => 'mirahezerequests-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $requester->getName() ) .
					Linker::userToolLinks( $requester->getId(), $requester->getName() ),
				'raw' => true,
			];
		}

		$formDescriptor += [
			'requestedDate' => [
				'label-message' => 'mirahezerequests-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->userTimeAndDate(
					$this->requestManager->getTimestamp(), $this->context->getUser()
				),
			],
			'reason' => [
				'label-message' => 'requestaccount-reason',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'requestaccount-' . $this->requestManager->getReason() . '-label'
				)->text(),
			],
		];

		$globalBlockReason = $this->requestManager->getGlobalBlockReason();
		if ( $globalBlockReason !== null ) {
			$formDescriptor['globalblock-reason'] = [
				'label-message' => 'requestaccount-globalblock-reason',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg( "requestaccount-globalblock-reason-$globalBlockReason" )->text(),
			];
		}

		$formDescriptor += [
			'explanation' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'requestaccount-explanation',
				'default' => $this->requestManager->getExplanation(),
				'section' => 'details',
			],
			'comments' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestaccount-comments',
				'default' => $this->requestManager->getComments(),
				'section' => 'details',
			],
		];

		$canSeeIp = $this->requestManager->canSeeIp( $this->context->getUser() );
		if ( $canSeeIp ) {
			$formDescriptor['ip'] = [
				'label-message' => 'mirahezerequests-label-ip',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->formatIp(),
				'raw' => true,
			];
		}

		if ( $this->requestManager->isFinalized() ) {
			$formDescriptor['resolved-info'] = [
				'type' => 'info',
				'default' => $this->buildResolvedInfoHtml(),
				'raw' => true,
				'section' => 'details',
			];

			return $formDescriptor;
		}

		$formDescriptor['status'] = [
			'label-message' => 'mirahezerequests-label-status',
			'type' => 'text',
			'readonly' => true,
			'section' => 'details',
			'default' => $this->context->msg(
				'mirahezerequests-status-' . $this->requestManager->getStatus()
			)->text(),
		];

		return $isHandler ? $formDescriptor + $this->getHandlingDescriptor( $canSeeIp ) : $formDescriptor;
	}

	private function isHandler(): bool {
		return $this->context->getAuthority()->isAllowed( 'handle-requestaccount' );
	}

	private function getHandlingDescriptor( bool $canSeeIp ): array {
		$authority = $this->context->getAuthority();
		$codex = new Codex();

		// The block details contain the target IP or range, so they follow
		// the same visibility rule as the IP address itself.
		$info = '';
		$blocks = $this->requestManager->getIpBlocks();
		if ( $blocks ) {
			$info .= $canSeeIp ?
				$codex->message()->setContentText( implode( "\n", $blocks ) )->build()->getHtml() :
				Html::warningBox(
					$this->context->msg( 'requestaccount-warning-ip-blocked' )->parse()
				);
		}

		foreach ( $this->requestManager->getNameWarnings() as $nameWarning ) {
			$info .= Html::warningBox(
				$this->context->msg( 'requestaccount-warning-name-check' )->parse() . $nameWarning
			);
		}

		$creatable = $this->authManager->canCreateAccount( $this->requestManager->getUsername() );
		// checked as the handler, so overridable checks pass here
		if (
			!$creatable->isGood() &&
			(
				UsernameValidator::getOverrideRight( $creatable ) === null ||
				!$this->requestManager->getNameWarnings()
			)
		) {
			$info .= Html::warningBox(
				$this->context->msg( 'requestaccount-warning-not-creatable' )
					->params( $this->requestManager->getUsername() )
					->params( $this->statusFormatter->getMessage( $creatable ) )
					->parse()
			);
		}

		$descriptor = [
			'handle-info' => [
				'type' => 'info',
				'default' => $info,
				'raw' => true,
				'section' => 'handling',
			],
		];

		// offered to anyone with the right, as the need can't be told here
		if (
			$this->extensionRegistry->isLoaded( 'TitleBlacklist' ) &&
			$authority->isAllowedAny( 'tboverride', 'tboverride-account' )
		) {
			$descriptor['ignore-titleblacklist'] = [
				'type' => 'check',
				'label-message' => 'titleblacklist-override',
				'section' => 'handling',
			];
		}

		if (
			$this->extensionRegistry->isLoaded( 'AntiSpoof' ) &&
			$authority->isAllowed( 'override-antispoof' )
		) {
			$descriptor['ignore-antispoof'] = [
				'type' => 'check',
				'label-message' => 'antispoof-ignore',
				'section' => 'handling',
			];
		}

		return $descriptor + [
			'submit-accept' => [
				'type' => 'submit',
				'buttonlabel-message' => 'mirahezerequests-label-accept',
				'disabled' => $this->requestManager->userExists(),
				'section' => 'handling',
			],
			'submit-decline' => [
				'type' => 'submit',
				'flags' => [ 'destructive', 'primary' ],
				'buttonlabel-message' => 'mirahezerequests-label-decline',
				'section' => 'handling',
			],
			'submit-decline-reason' => [
				'type' => 'text',
				'label-message' => 'mirahezerequests-label-decline-reason',
				'section' => 'handling',
				'maxlength' => FieldValidator::MAX_TEXT_LENGTH,
				'validation-callback' => $this->isValidDeclineReason( ... ),
			],
		];
	}

	private function isValidDeclineReason( ?string $reason, array $alldata ): Message|bool {
		if ( isset( $alldata['submit-decline'] ) && ( !$reason || ctype_space( $reason ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return FieldValidator::validateLength( $reason, FieldValidator::MAX_TEXT_LENGTH, $this->context );
	}

	private function buildResolvedInfoHtml(): string {
		$rows = [
			'mirahezerequests-label-status' => htmlspecialchars( $this->context->msg(
				'mirahezerequests-status-' . $this->requestManager->getStatus()
			)->text() ),
		];

		$completedTimestamp = $this->requestManager->getCompletedTimestamp();
		if ( $completedTimestamp ) {
			$rows['mirahezerequests-label-completed-date'] = htmlspecialchars(
				$this->context->getLanguage()->userTimeAndDate( $completedTimestamp, $this->context->getUser() )
			);
		}

		$completedUser = $this->requestManager->getCompletedUser();
		if ( $completedUser ) {
			$rows['mirahezerequests-label-donetby'] = htmlspecialchars( $completedUser->getName() ) .
				Linker::userToolLinks( $completedUser->getId(), $completedUser->getName() );
		}

		$notes = $this->requestManager->getNotes();
		if ( $notes !== '' ) {
			$rows['mirahezerequests-label-reason'] = nl2br( htmlspecialchars( $notes ) );
		}

		$html = Html::openElement( 'dl', [ 'class' => 'mirahezerequests-resolved-info' ] );
		foreach ( $rows as $labelKey => $valueHtml ) {
			$html .= Html::element( 'dt',
				[ 'style' => 'font-weight:bold;margin-top:0.5em;' ],
				$this->context->msg( $labelKey )->text()
			);
			$html .= Html::rawElement( 'dd', [ 'style' => 'margin-left:1.5em;' ], $valueHtml );
		}
		$html .= Html::closeElement( 'dl' );

		return $html;
	}

	private function formatRequestsWithSameEmail(): string {
		// one more to know whether to link the full list
		$others = $this->requestManager->getOtherRequestsWithSameEmail( self::SAME_EMAIL_LIMIT + 1 );
		if ( !$others ) {
			return $this->context->msg( 'requestaccount-same-email-none' )->escaped();
		}

		$items = [];
		foreach ( array_slice( $others, 0, self::SAME_EMAIL_LIMIT ) as $other ) {
			$items[] = $this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'RequestAccountQueue', (string)$other['id'] ),
				"#{$other['id']}",
				[],
				AccountCreator::FULL_PROVIDERS_QUERY
			) . ' ' . $this->context->msg( 'parentheses' )
				->params( $this->context->msg( "mirahezerequests-status-{$other['status']}" )->text() )
				->escaped();
		}

		$html = $this->context->getLanguage()->commaList( $items );

		if ( count( $others ) > self::SAME_EMAIL_LIMIT ) {
			$html .= ' ' . $this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'RequestAccountQueue' ),
				$this->context->msg( 'requestaccount-same-email-all' )->text(),
				[],
				[ 'email' => $this->requestManager->getEmail(), 'status' => '*', 'type' => 'all' ]
			);
		}

		return $html;
	}

	private function formatIp(): string {
		$ip = $this->requestManager->getIp();
		if ( $ip === '' ) {
			return $this->context->msg( 'requestaccount-ip-purged' )->escaped();
		}

		$authority = $this->context->getAuthority();
		$links = [
			$this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'Contributions', $ip ),
				$this->context->msg( 'contribslink' )->text()
			),
		];

		// only actions the viewer can perform, like Special:Contributions
		if ( $authority->isAllowed( 'block' ) ) {
			$links[] = $this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'Block', $ip ),
				$this->context->msg( 'requestaccount-iplink-block' )->text()
			);
		}

		$links[] = $this->linkRenderer->makeKnownLink(
			SpecialPage::getTitleFor( 'BlockList' ),
			$this->context->msg( 'requestaccount-iplink-blocklist' )->text(),
			[],
			[ 'wpTarget' => $ip ]
		);
		$links[] = $this->linkRenderer->makeKnownLink(
			SpecialPage::getTitleFor( 'Log', 'block' ),
			$this->context->msg( 'requestaccount-iplink-blocklog' )->text(),
			[],
			[ 'page' => "User:$ip" ]
		);

		if ( $this->extensionRegistry->isLoaded( 'GlobalBlocking' ) ) {
			if ( $authority->isAllowed( 'globalblock' ) ) {
				$links[] = $this->linkRenderer->makeKnownLink(
					SpecialPage::getTitleFor( 'GlobalBlock', $ip ),
					$this->context->msg( 'requestaccount-iplink-globalblock' )->text()
				);
			}

			$links[] = $this->linkRenderer->makeKnownLink(
				SpecialPage::getTitleFor( 'GlobalBlockList', $ip ),
				$this->context->msg( 'requestaccount-iplink-globalblocklist' )->text()
			);
		}

		return htmlspecialchars( $ip ) . ' ' . $this->context->msg( 'parentheses' )
			->rawParams( $this->context->getLanguage()->pipeList( $links ) )
			->escaped();
	}

	/** @inheritDoc */
	public function getForm( int $requestId ): CodexHTMLFormTabs {
		// The handling section contains Codex message boxes.
		$this->context->getOutput()->addModuleStyles( 'mediawiki.codex.messagebox.styles' );
		$this->requestManager->getById( $requestId );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new CodexHTMLFormTabs( $formDescriptor, $this->context, 'requestaccount-section' );

		$htmlForm->setId( 'mirahezerequests-requestaccount-viewer' );
		$htmlForm->addHiddenFields( AccountCreator::FULL_PROVIDERS_QUERY );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback( $this->submitForm( ... ) );

		return $htmlForm;
	}

	/**
	 * $form is required to match HTMLForm's submit-callback signature.
	 * @suppress PhanUnusedProtectedMethodParameter
	 */
	protected function submitForm(
		array $formData,
		HTMLForm $form,
	): bool|Status {
		// Non-handlers get no buttons, but could still post
		if ( !$this->isHandler() ) {
			return Status::newFatal( 'mirahezerequests-nopermission' );
		}

		if ( $this->requestManager->isFinalized() ) {
			return Status::newFatal( 'mirahezerequests-status-conflict' );
		}

		if ( isset( $formData['submit-accept'] ) ) {
			return $this->accept( $formData );
		}

		if ( isset( $formData['submit-decline'] ) ) {
			return $this->decline( $formData );
		}

		return Status::newFatal( 'requestaccount-error-no-action' );
	}

	private function accept( array $formData ): bool|Status {
		$user = $this->context->getUser();

		// claim first so no other handler acts meanwhile
		if ( !$this->requestManager->transitionStatus(
			MirahezeRequestsStatus::Pending->value, MirahezeRequestsStatus::Starting->value
		) ) {
			return Status::newFatal( 'mirahezerequests-status-conflict' );
		}

		$completed = false;
		try {
			$status = $this->accountCreator->createAccount(
				$this->requestManager->getId(),
				$this->requestManager->getUsername(),
				$this->requestManager->getEmail(),
				$user,
				ignoreTitleBlacklist: !empty( $formData['ignore-titleblacklist'] ),
				ignoreAntiSpoof: !empty( $formData['ignore-antispoof'] ),
				webRequest: $this->context->getRequest(),
			);
			if ( !$status->isGood() ) {
				return $status;
			}

			$notes = $this->context->msg( 'requestaccount-notes-created' )->inContentLanguage()->text();
			$this->requestManager->resolve( MirahezeRequestsStatus::Complete->value, $user, $notes );
			$completed = true;
			$this->log( 'accept', '' );

			// The shared manager may load another request before the callback runs.
			$request = clone $this->requestManager;
			$temporaryPassword = $status->getValue();
			$this->dbService->getDbw()->onTransactionCommitOrIdle(
				function () use ( $request, $temporaryPassword ): void {
					$mailStatus = $this->requestMailer->sendApproved( $request, $temporaryPassword );
					if ( !$mailStatus->isGood() ) {
						$request->setNotes(
							$this->context->msg( 'requestaccount-notes-created-mail-failed' )
								->params( $this->statusFormatter->getMessage( $mailStatus ) )
								->inContentLanguage()
								->text()
						);
					}
				},
				__METHOD__
			);
			$this->redirectToRequest();

			return true;
		} finally {
			// don't leave the request claimed if creation failed or threw
			if ( !$completed ) {
				$this->requestManager->transitionStatus(
					MirahezeRequestsStatus::Starting->value, MirahezeRequestsStatus::Pending->value
				);
			}
		}
	}

	private function decline( array $formData ): bool|Status {
		if ( !$this->requestManager->transitionStatus(
			MirahezeRequestsStatus::Pending->value, MirahezeRequestsStatus::Declined->value
		) ) {
			return Status::newFatal( 'mirahezerequests-status-conflict' );
		}

		$reason = trim( $formData['submit-decline-reason'] ?? '' );
		$this->requestManager->resolve( MirahezeRequestsStatus::Declined->value, $this->context->getUser(), $reason );
		$this->log( 'decline', $reason );

		if ( !$this->requestMailer->sendDeclined( $this->requestManager, $reason )->isGood() ) {
			$this->context->getOutput()->addHTML(
				Html::warningBox( $this->context->msg( 'requestaccount-decline-mail-failed' )->parse() )
			);
			return true;
		}

		$this->redirectToRequest();
		return true;
	}

	private function log( string $subtype, string $comment ): void {
		$logEntry = new ManualLogEntry( 'requestaccount', $subtype );
		$logEntry->setPerformer( $this->context->getUser() );
		$logEntry->setTarget( $this->getRequestTitle() );
		$logEntry->setComment( $comment );
		$logEntry->setParameters( [ '4::requestTarget' => $this->requestManager->getUsername() ] );
		$logEntry->publish( $logEntry->insert() );
	}

	private function redirectToRequest(): void {
		// so reloading doesn't resubmit
		$this->context->getOutput()->redirect(
			$this->getRequestTitle()->getFullURL( AccountCreator::FULL_PROVIDERS_QUERY )
		);
	}

	private function getRequestTitle(): Title {
		return SpecialPage::getTitleFor( 'RequestAccountQueue', (string)$this->requestManager->getId() );
	}
}
