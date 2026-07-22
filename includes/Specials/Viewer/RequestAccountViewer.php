<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\Message\Message;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\MirahezeRequests\CodexHTMLFormTabs;
use Miraheze\MirahezeRequests\Requests\RequestAccountManager;
use Wikimedia\Codex\Utility\Codex;

class RequestAccountViewer extends RequestViewer {
	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly RequestAccountManager $requestManager
	) {
	}

	public function getFormDescriptor(): array {
		$codex = new Codex();
		$authority = $this->context->getAuthority();

		if ( !$authority->isAllowed( 'handle-requestaccount' ) ) {
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
			'requester' => [
				'label-message' => 'mirahezerequests-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->requestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->requestManager->getRequester()->getId(),
						$this->requestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'mirahezerequests-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'mirahezerequests-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'mirahezerequests-status-' . $this->requestManager->getStatus()
				)->text(),
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
			'explanation' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'requestaccount-explanation',
				'default' => $this->requestManager->getExplanation(),
				'raw' => true,
				'section' => 'details',
			],
			'comments' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestaccount-comments',
				'default' => $this->requestManager->getComments(),
				'raw' => true,
				'section' => 'details',
			],
		];

		$info = '';
		$invalidStatus = $this->requestManager->invalidStatus();

		$blocks = $this->requestManager->getIpBlocks();
		if ( $blocks ) {
			$info .= $codex->message()->setContentText( implode( "\n", $blocks ) )->build()->getHtml();
		}

		if ( $authority->isAllowed( 'handle-requestaccount' ) ) {
			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => $info,
					'raw' => true,
					'section' => 'handling',
				],
				'submit-accept' => [
					'type' => 'submit',
					'buttonlabel-message' => 'mirahezerequests-label-accept',
					'disabled' => $invalidStatus || $this->requestManager->userExists(),
					'section' => 'handling',
				],
				'submit-decline' => [
					'type' => 'submit',
					'flags' => [ 'destructive', 'primary' ],
					'buttonlabel-message' => 'mirahezerequests-label-decline',
					'disabled' => $invalidStatus,
					'section' => 'handling',
				],
				'submit-decline-reason' => [
					'type' => 'text',
					'label-message' => 'mirahezerequests-label-decline-reason',
					'section' => 'handling',
					'validation-callback' => [ $this, 'isValidDeclineReason' ],
				],
			];
		}

		return $formDescriptor;
	}

	public function getForm( int $requestId ): CodexHTMLFormTabs {
		$this->requestManager->getById( $requestId );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new CodexHTMLFormTabs( $formDescriptor, $this->context, 'requestaccount-section' );

		$htmlForm->setId( 'mirahezerequests-requestaccount-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				$this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	public function isValidDeclineReason( ?string $reason, array $alldata ): Message|true {
		if ( isset( $alldata['submit-decline'] ) && ( !$reason || ctype_space( $reason ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	protected function submitForm(
		array $formData,
		HTMLForm $form,
	): void {
		$out = $this->context->getOutput();

		if ( $this->requestManager->invalidStatus() ) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'mirahezerequests-status-conflict' )->escaped()
			) );
			return;
		}

		// Always act on the stored request data, never on the submitted
		// form values, since the readonly fields can still be tampered
		// with in a raw POST request.
		$username = $this->requestManager->getUsername();
		$email = $this->requestManager->getEmail();

		if ( isset( $formData['submit-accept'] ) ) {
			$this->requestManager->executeJob( $username, $email );

			$logEntry = new ManualLogEntry( 'requestaccount', 'accept' );
			$logEntry->setPerformer( $this->context->getUser() );
			$logEntry->setTarget( SpecialPage::getTitleValueFor( 'RequestAccountQueue', (string)$this->requestManager->getId() ) );
			$logEntry->setParameters( [ '4::requestTarget' => $username ] );
			$logEntry->publish( $logEntry->insert() );

			$this->requestManager->setStatus( self::STATUS_COMPLETE );

			$out->addHTML( Html::successBox(
				$this->context->msg( 'mirahezerequests-request-accepted' )->escaped()
			) );

			return;
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$this->requestManager->sendDeclineEmail( $formData['submit-decline-reason'] );

			$logEntry = new ManualLogEntry( 'requestaccount', 'decline' );
			$logEntry->setPerformer( $this->context->getUser() );
			$logEntry->setTarget( SpecialPage::getTitleValueFor( 'RequestAccountQueue', (string)$this->requestManager->getId() ) );
			$logEntry->setComment( $formData['submit-decline-reason'] );
			$logEntry->setParameters( [ '4::requestTarget' => $username ] );
			$logEntry->publish( $logEntry->insert() );

			$this->requestManager->setStatus( self::STATUS_DECLINED );

			$out->addHTML( Html::successBox(
				$this->context->msg( 'mirahezerequests-request-declined' )->escaped()
			) );
		}
	}
}
