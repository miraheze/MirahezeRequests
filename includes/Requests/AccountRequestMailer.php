<?php

namespace Miraheze\MirahezeRequests\Requests;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Mail\IEmailer;
use MediaWiki\Mail\MailAddress;
use MediaWiki\MainConfigNames;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\ConfigNames;
use StatusValue;

/** Mails the requester in the language they used for the request. */
class AccountRequestMailer {

	public const CONSTRUCTOR_OPTIONS = [
		ConfigNames::AccountConfirmationExpiry,
		MainConfigNames::NewPasswordExpiry,
		MainConfigNames::PasswordSender,
	];

	public function __construct(
		private readonly IEmailer $emailer,
		private readonly MessageLocalizer $messageLocalizer,
		private readonly ServiceOptions $options,
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function sendConfirmation( RequestAccountManager $request, string $token ): StatusValue {
		$language = $request->getLanguage();
		$url = SpecialPage::getTitleFor( 'RequestAccount', "confirm/$token" )->getCanonicalURL();

		// not copied: confirming proves the requested address only
		return $this->send(
			new MailAddress( $request->getEmail(), $request->getUsername() ),
			$this->messageLocalizer->msg( 'requestaccount-confirm-email-title' )->inLanguage( $language )->text(),
			$this->messageLocalizer->msg( 'requestaccount-confirm-email-text' )
				->plaintextParams( $request->getUsername(), $url, $request->getIp() )
				->durationParams( $this->options->get( ConfigNames::AccountConfirmationExpiry ) )
				->inLanguage( $language )->text()
		);
	}

	public function sendApproved( RequestAccountManager $request, string $temporaryPassword ): StatusValue {
		$language = $request->getLanguage();

		return $this->sendDecision(
			$request,
			$this->messageLocalizer->msg( 'requestaccount-created-email-title' )->inLanguage( $language )->text(),
			$this->messageLocalizer->msg( 'requestaccount-created-email-text' )
				->plaintextParams(
					$request->getUsername(),
					$temporaryPassword,
					SpecialPage::getTitleFor( 'Userlogin' )->getCanonicalURL()
				)
				->durationParams( $this->options->get( MainConfigNames::NewPasswordExpiry ) )
				->inLanguage( $language )->text()
		);
	}

	public function sendDeclined( RequestAccountManager $request, string $reason ): StatusValue {
		$language = $request->getLanguage();

		return $this->sendDecision(
			$request,
			$this->messageLocalizer->msg( 'requestaccount-declined-email-title' )->inLanguage( $language )->text(),
			$this->messageLocalizer->msg( 'requestaccount-declined-email-text' )
				->plaintextParams( $reason )
				->inLanguage( $language )->text()
		);
	}

	/**
	 * @return StatusValue Of the mail to the requested address; the copy may fail on its own
	 */
	private function sendDecision( RequestAccountManager $request, string $subject, string $body ): StatusValue {
		$status = $this->send( new MailAddress( $request->getEmail(), $request->getUsername() ), $subject, $body );

		$ccEmail = $request->getRequesterCcEmail();
		if ( $ccEmail && $ccEmail !== $request->getEmail() ) {
			$this->send( new MailAddress( $ccEmail ), $subject, $body );
		}

		return $status;
	}

	private function send( MailAddress $to, string $subject, string $body ): StatusValue {
		// $wgPasswordSender, as the system user has no email address
		$from = new MailAddress(
			$this->options->get( MainConfigNames::PasswordSender ),
			$this->messageLocalizer->msg( 'emailsender' )->inContentLanguage()->text()
		);

		return $this->emailer->send( [ $to ], $from, $subject, $body );
	}
}
