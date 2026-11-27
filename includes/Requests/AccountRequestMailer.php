<?php

declare( strict_types = 1 );

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Mail\MailAddress;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\ConfigNames;
use StatusValue;
use Wikimedia\Message\MessageSpecifier;

/** Mails the requester in the language they used for the request. */
class AccountRequestMailer {

	public const array CONSTRUCTOR_OPTIONS = [
		ConfigNames::AccountConfirmationExpiry,
		MainConfigNames::NewPasswordExpiry,
		MainConfigNames::PasswordSender,
	];

	public function __construct(
		private readonly IEmailer $emailer,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function sendConfirmation(
		RequestAccountManager $request,
		string $token,
		MessageLocalizer $messageLocalizer
	): StatusValue {
		$language = $request->getLanguage();
		$url = SpecialPage::getTitleFor( 'RequestAccount', "confirm/$token" )->getCanonicalURL();

		// not copied: confirming proves the requested address only
		return $this->send(
			new MailAddress( $request->getEmail(), $request->getUsername() ),
			$messageLocalizer->msg( 'requestaccount-confirm-email-title' )->inLanguage( $language )->text(),
			$messageLocalizer->msg( 'requestaccount-confirm-email-text' )
				->plaintextParams( $request->getUsername(), $url, $request->getIp() )
				->durationParams( $this->options->get( ConfigNames::AccountConfirmationExpiry ) )
				->inLanguage( $language )->text(),
			$messageLocalizer,
		);
	}

	public function sendApproved(
		RequestAccountManager $request,
		string $temporaryPassword,
		MessageLocalizer $messageLocalizer,
	): StatusValue {
		$language = $request->getLanguage();

		return $this->sendDecision(
			$request,
			$messageLocalizer->msg( 'requestaccount-created-email-title' )->inLanguage( $language )->text(),
			$messageLocalizer->msg( 'requestaccount-created-email-text' )
				->plaintextParams(
					$request->getUsername(),
					$temporaryPassword,
					SpecialPage::getTitleFor( 'Userlogin' )->getCanonicalURL()
				)
				->durationParams( $this->options->get( MainConfigNames::NewPasswordExpiry ) )
				->inLanguage( $language )->text(),
			// The CC address belongs to the requester account and can change after confirmation
			$messageLocalizer->msg( 'requestaccount-created-email-cc-text' )
				->plaintextParams( $request->getUsername() )
				->inLanguage( $language )->text(),
			messageLocalizer: $messageLocalizer,
		);
	}

	public function sendDeclined(
		RequestAccountManager $request,
		string $reason,
		MessageLocalizer $messageLocalizer,
	): StatusValue {
		$language = $request->getLanguage();

		return $this->sendDecision(
			$request,
			$messageLocalizer->msg( 'requestaccount-declined-email-title' )->inLanguage( $language )->text(),
			$messageLocalizer->msg( 'requestaccount-declined-email-text' )
				->plaintextParams( $reason )
				->inLanguage( $language )->text(),
			ccBody: null,
			messageLocalizer: $messageLocalizer
		);
	}

	/**
	 * @return StatusValue Of the mail to the requested address; the copy may fail on its own
	 */
	private function sendDecision(
		RequestAccountManager $request,
		string $subject,
		string $body,
		?string $ccBody,
		MessageLocalizer $messageLocalizer,
	): StatusValue {
		$status = $this->send(
			new MailAddress( $request->getEmail(), $request->getUsername() ), $subject, $body, $messageLocalizer
		);

		$ccEmail = $request->getRequesterCcEmail();
		if ( $ccEmail && $ccEmail !== $request->getEmail() ) {
			$this->send( new MailAddress( $ccEmail ), $subject, $ccBody ?? $body, $messageLocalizer );
		}

		return $status;
	}

	private function send(
		MailAddress $to,
		string $subject,
		string $body,
		MessageLocalizer $messageLocalizer,
	): StatusValue {
		// $wgPasswordSender, as the system user has no email address
		$from = new MailAddress(
			$this->options->get( MainConfigNames::PasswordSender ),
			$messageLocalizer->msg( 'emailsender' )->inContentLanguage()->text()
		);

		$status = $this->emailer->send( [ $to ], $from, $subject, $body );
		if ( !$status->isGood() ) {
			$error = implode( "\n", array_map(
				static fn ( MessageSpecifier $message ): string => Message::newFromSpecifier( $message )
					->inLanguage( 'en' )->plain(),
				$status->getMessages()
			) );

			LoggerFactory::getInstance( 'MirahezeRequests' )->error(
				'Account request email delivery failed: {error}',
				[ 'error' => $error ]
			);
		}

		return $status;
	}
}
