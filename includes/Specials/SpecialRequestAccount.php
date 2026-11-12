<?php

namespace Miraheze\MirahezeRequests\Specials;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Exception\ErrorPageError;
use MediaWiki\Exception\UserBlockedError;
use MediaWiki\Extension\ConfirmEdit\Hooks as ConfirmEditHooks;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\MainConfigNames;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use Miraheze\MirahezeRequests\ConfigNames;
use Miraheze\MirahezeRequests\FieldValidator;
use Miraheze\MirahezeRequests\Hooks\Handlers\ConfirmEdit;
use Miraheze\MirahezeRequests\MirahezeRequestsConstants;
use Miraheze\MirahezeRequests\Requests\AccountCreator;
use Miraheze\MirahezeRequests\Requests\AccountRequestMailer;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Miraheze\MirahezeRequests\Requests\UsernameValidator;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsDatabaseService;
use StatusValue;

class SpecialRequestAccount extends SpecialRequest {
	// should be customizable in config?
	public const GLOBALBLOCK_REASONS = [
		'crosswiki-abuse',
		'crosswiki-spam',
		'crosswiki-vandalism',
		'longterm-abuse',
		'proxy',
		'trust-safety',
		'other',
	];

	private bool $captchaRequired = false;

	private ?string $submittedEmail = null;

	/**
	 * Last validated name, so submitting doesn't rerun AuthManager.
	 *
	 * @var ?array{name:string,warnings:string[]}
	 */
	private ?array $validatedName = null;

	public function __construct(
		MirahezeRequestsDatabaseService $dbService,
		private readonly ExtensionRegistry $extensionRegistry,
		private readonly AccountRequestMailer $requestMailer,
		private readonly RequestAccountManager $requestManager,
		private readonly UsernameValidator $usernameValidator,
	) {
		parent::__construct( 'Account', 'request-account', $dbService );
	}

	/**
	 * @inheritDoc
	 * @throws ErrorPageError
	 * @throws UserBlockedError
	 */
	public function execute( $par ): void {
		$this->setHeaders();
		// Html::noticeBox, warningBox, errorBox and successBox use Codex classes.
		$this->getOutput()->addModuleStyles( 'mediawiki.codex.messagebox.styles' );
		$this->dbService->assertCentralWiki();

		if ( !$this->getConfig()->get( MainConfigNames::EnableEmail ) ) {
			throw new ErrorPageError( 'requestaccount', 'requestaccount-error-email-disabled' );
		}

		$subpage = explode( '/', $par ?? '', 2 );
		if ( $subpage[0] === 'confirm' ) {
			$this->checkReadOnly();
			$this->showConfirmation( $subpage[1] ?? '' );
			return;
		}

		if ( !FullProvidersGuard::ensure( $this, $this->extensionRegistry, $par ) ) {
			return;
		}

		// sitewide blocks are who this page is for; only blocks from requesting apply
		$block = $this->getUser()->getBlock();
		if ( $block && $block->appliesToRight( 'request-account' ) ) {
			throw new UserBlockedError( $block );
		}

		// requests submitted before closing can still be confirmed
		if ( !$this->getConfig()->get( ConfigNames::AccountRequestsOpen ) ) {
			$this->getOutput()->addHTML( Html::warningBox( $this->msg( 'requestaccount-closed' )->parse() ) );
			return;
		}

		$this->captchaRequired = $this->isCaptchaRequired();

		parent::execute( $par );
	}

	/** @inheritDoc */
	protected function getFormFields(): array {
		$globalBlockReasons = [];
		foreach ( self::GLOBALBLOCK_REASONS as $reason ) {
			$globalBlockReasons["requestaccount-globalblock-reason-$reason"] = $reason;
		}

		$fields = [
			'email' => [
				'type' => 'email',
				'label-message' => 'requestaccount-email',
				'required' => true,
				'validation-callback' => fn ( ?string $email ) => FieldValidator::validateEmail( $email, $this ),
			],
			'reason' => [
				'type' => 'radio',
				'label-message' => 'requestaccount-reason',
				'help-message' => 'requestaccount-reason-help',
				'options-messages' => [
					'requestaccount-other-label' => 'other',
					'requestaccount-abusefilter-label' => 'abusefilter',
					'requestaccount-captcha-label' => 'captcha',
					'requestaccount-globalblock-label' => 'globalblock',
				],
				'default' => 'other',
				'required' => true,
			],
			'globalblock-reason' => [
				'type' => 'select',
				'label-message' => 'requestaccount-globalblock-reason',
				'options-messages' => $globalBlockReasons,
				'hide-if' => [ '!==', 'reason', 'globalblock' ],
			],
			'explanation' => [
				'type' => 'textarea',
				'label-message' => 'requestaccount-explanation',
				'help-message' => 'requestaccount-explanation-help',
				'required' => true,
				'maxlength' => FieldValidator::MAX_TEXT_LENGTH,
				'validation-callback' => fn ( ?string $text ) => FieldValidator::validateLength(
					$text, FieldValidator::MAX_TEXT_LENGTH, $this
				),
			],
			'username' => [
				'type' => 'text',
				'label-message' => 'requestaccount-username',
				'help-message' => 'requestaccount-username-help',
				'required' => true,
				'maxlength' => 255,
				'validation-callback' => $this->isValidUsername( ... ),
			],
			'comments' => [
				'type' => 'textarea',
				'label-message' => 'requestaccount-comments',
				'maxlength' => FieldValidator::MAX_TEXT_LENGTH,
				'validation-callback' => fn ( ?string $text ) => FieldValidator::validateLength(
					$text, FieldValidator::MAX_TEXT_LENGTH, $this
				),
			],
		];

		// the copy goes to the account the request is submitted from
		if ( $this->getUser()->isNamed() ) {
			$fields['CCemail'] = [
				'type' => 'check',
				'label-message' => 'requestaccount-ccemail',
			];
		}

		$fields += [
			'consent' => [
				'type' => 'check',
				'label-message' => 'requestaccount-consent',
				'required' => true,
			],
		];

		if ( $this->captchaRequired ) {
			$fields['captcha'] = [
				'type' => 'info',
				'label-message' => 'captcha-label',
				'default' => $this->getCaptchaHtml(),
				'raw' => true,
			];
		}

		return $fields;
	}

	/** @inheritDoc */
	protected function alterForm( HTMLForm $form ): void {
		// validating the username needs every AuthManager provider
		$form->addHiddenFields( AccountCreator::FULL_PROVIDERS_QUERY );
	}

	public function isValidUsername( ?string $username ): StatusValue|true {
		// not `!$username`: also true for "0", a legal username
		if ( $username === null || $username === '' ) {
			return StatusValue::newFatal( 'htmlform-required' );
		}

		$status = $this->usernameValidator->validate( $username, $this->getUser() );
		if ( !$status->isGood() ) {
			return $status;
		}

		$this->validatedName = $status->getValue();
		return true;
	}

	/** @inheritDoc */
	protected function getInsertRow( array $data, string $timestamp ): array {
		return [
			'request_timestamp' => $timestamp,
			'request_email' => trim( $data['email'] ),
			'request_username' => $this->validatedName['name'],
			'request_name_warnings' => json_encode( $this->validatedName['warnings'] ),
			'request_reason' => $data['reason'],
			'request_globalblock_reason' => $data['reason'] === 'globalblock' ? $data['globalblock-reason'] : null,
			'request_explanation' => $data['explanation'],
			'request_comments' => $data['comments'] ?? '',
			'request_ccemail' => (int)!empty( $data['CCemail'] ),
			'request_ip' => $this->getRequest()->getIP(),
			'request_language' => $this->getLanguage()->getCode(),
		];
	}

	/** @inheritDoc */
	public function onSubmit( array $data ): Status {
		$user = $this->getUser();

		if (
			$this->captchaRequired &&
			!ConfirmEditHooks::getInstance( ConfirmEdit::TRIGGER )
				->passCaptchaLimitedFromRequest( $this->getRequest(), $user )
		) {
			return Status::newFatal( 'requestaccount-error-captcha' );
		}

		if ( $user->pingLimiter( 'requestaccount' ) ) {
			return Status::newFatal( 'actionthrottledtext' );
		}

		$email = trim( $data['email'] );

		if ( $this->requestManager->hasOpenRequestForName( $this->validatedName['name'] ) ) {
			return Status::newFatal( 'requestaccount-error-duplicate-username' );
		}

		if ( $this->requestManager->hasActiveRequestForEmail( $email ) ) {
			return Status::newFatal( 'requestaccount-error-duplicate-email' );
		}

		[ $id, $token ] = $this->requestManager->createUnconfirmed(
			$this->getInsertRow( $data, $this->dbService->getDbw()->timestamp() ),
			// a logged-out actor would keep the IP past its retention
			$user->isNamed() ? $user : null
		);

		$this->requestManager->getById( $id );
		if ( !$this->requestMailer->sendConfirmation( $this->requestManager, $token )->isGood() ) {
			$this->requestManager->deleteUnconfirmed( $id );
			return Status::newFatal( 'requestaccount-error-email-send' );
		}

		DeferredUpdates::addCallableUpdate( function () {
			$this->requestManager->purgeExpiredData();
		} );

		$this->submittedEmail = $email;

		return Status::newGood();
	}

	/** @inheritDoc */
	public function onSuccess(): void {
		// nothing is submitted until the link is confirmed
		$this->getOutput()->addHTML( Html::noticeBox(
			$this->msg( 'requestaccount-success' )
				->plaintextParams( $this->submittedEmail ?? '' )
				->durationParams( $this->getConfig()->get( ConfigNames::AccountConfirmationExpiry ) )
				->parseAsBlock(),
			''
		) );
	}

	private function showConfirmation( string $token ): void {
		$out = $this->getOutput();
		$id = $token !== '' ? $this->requestManager->findUnconfirmedIdByToken( $token ) : null;

		if ( $id === null ) {
			$out->addHTML( Html::errorBox( $this->msg( 'requestaccount-confirm-invalid' )->parse() ) );
			return;
		}

		$this->requestManager->getById( $id );

		// prevent auto link scanners in mail clients
		$form = HTMLForm::factory( 'codex', [
			'info' => [
				'type' => 'info',
				'default' => $this->msg( 'requestaccount-confirm-info', $this->requestManager->getUsername() )->text(),
			],
		], $this->getContext(), 'requestaccount-confirm' );

		$form
			->setWrapperLegendMsg( 'requestaccount-confirm-legend' )
			->setSubmitTextMsg( 'requestaccount-confirm-submit' )
			->setSubmitCallback( $this->confirmRequest( ... ) );

		if ( !$form->show() ) {
			return;
		}

		$requester = $this->requestManager->getRequester();
		if ( !$requester || !$this->getUser()->equals( $requester ) ) {
			$out->addHTML( Html::successBox( $this->msg( 'requestaccount-confirm-success' )->parse() ) );
			return;
		}

		$requestId = (string)$this->requestManager->getId();
		$requestLink = $this->getLinkRenderer()->makeLink(
			SpecialPage::getTitleFor( 'RequestAccountQueue', $requestId ),
			$requestId
		);
		$out->addHTML( Html::successBox(
			$this->msg( 'mirahezerequests-success' )->rawParams( $requestLink )->parse()
		) );
	}

	private function confirmRequest(): Status {
		$status = $this->requestManager->confirm();
		if ( !$status->isGood() ) {
			return Status::wrap( $status );
		}

		$logEntry = new ManualLogEntry( 'requestaccount', 'request' );
		// the requester has no account and must not be identified by IP
		$logEntry->setPerformer( User::newSystemUser( MirahezeRequestsConstants::SYSTEM_USER, [ 'steal' => true ] ) );
		$logEntry->setTarget(
			SpecialPage::getTitleFor( 'RequestAccountQueue', (string)$this->requestManager->getId() )
		);
		$logEntry->setParameters( [ '4::requestTarget' => $this->requestManager->getUsername() ] );
		$logEntry->publish( $logEntry->insert() );

		return Status::newGood();
	}

	private function isCaptchaRequired(): bool {
		if ( !$this->extensionRegistry->isLoaded( 'ConfirmEdit' ) ) {
			return false;
		}

		$captcha = ConfirmEditHooks::getInstance( ConfirmEdit::TRIGGER );
		return $captcha->triggersCaptcha( ConfirmEdit::TRIGGER ) &&
			!$captcha->canSkipCaptcha( $this->getUser() );
	}

	private function getCaptchaHtml(): string {
		// captchas store their solution in the session
		$this->getRequest()->getSession()->persist();

		$captcha = ConfirmEditHooks::getInstance( ConfirmEdit::TRIGGER );
		$captcha->setTrigger( ConfirmEdit::TRIGGER );
		$captcha->setAction( ConfirmEdit::TRIGGER );

		$out = $this->getOutput();
		// some captchas (e.g. SimpleCaptcha) use OOUI
		$out->enableOOUI();
		$formInformation = $captcha->getFormInformation( 1, $out );
		$formMetainfo = $formInformation;
		unset( $formMetainfo['html'] );
		$captcha->addFormInformationToOutput( $out, $formMetainfo );

		return Html::rawElement( 'div', [ 'class' => 'captcha' ], $formInformation['html'] );
	}

	/** @inheritDoc */
	public function requiresUnblock(): bool {
		return false;
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'login';
	}
}
