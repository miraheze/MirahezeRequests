<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Language\MessageLocalizer;
use MediaWiki\Message\Message;
use MediaWiki\Parser\Sanitizer;

/** Server-side checks, as HTMLForm leaves maxlength to the browser. */
class FieldValidator {

	/** Characters allowed in free text fields such as the explanation or a decline reason. */
	public const MAX_TEXT_LENGTH = 2000;

	/** Bytes of the email address columns */
	private const MAX_EMAIL_LENGTH = 255;

	public static function validateEmail( ?string $email, MessageLocalizer $messageLocalizer ): bool|Message {
		$email = trim( $email ?? '' );
		if ( $email === '' ) {
			// handled by 'required'
			return true;
		}

		if ( strlen( $email ) > self::MAX_EMAIL_LENGTH || !Sanitizer::validateEmail( $email ) ) {
			return $messageLocalizer->msg( 'invalidemailaddress' );
		}

		return true;
	}

	public static function validateLength(
		?string $text,
		int $maxLength,
		MessageLocalizer $messageLocalizer
	): bool|Message {
		if ( mb_strlen( $text ?? '' ) > $maxLength ) {
			return $messageLocalizer->msg( 'mirahezerequests-error-too-long' )->numParams( $maxLength );
		}

		return true;
	}
}
