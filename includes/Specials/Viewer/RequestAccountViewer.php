<?php

namespace Miraheze\MirahezeRequests\Specials\Viewer;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use Miraheze\MirahezeRequests\CodexHTMLFormTabs;
use Miraheze\MirahezeRequests\MirahezeRequestsStatus;
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
		$user = $this->context->getUser();
		$authority = $this->context->getAuthority();

		if ( !$authority->isAllowed( 'handle-requestaccount' ) ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'mirahezerequests-nopermission' )->escaped() )
			);
			return [];
		}

		$formDescriptor = [
			'username' => [
				'label-message' => 'mirahezerequests-label-username',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getUsername(),
			],
			'email' => [
				'label-message' => 'mirahezerequests-label-email',
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
					'mirahezerequests-label-' . $this->requestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'label-message' => 'mirahezerequests-label-reason',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'mirahezerequests-label-' . $this->requestManager->getReason()
				)->text(),
			],
			'explanation' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'mirahezerequests-label-explanation',
				'default' => $this->requestManager->getExplanation(),
				'raw' => true,
				'section' => 'details',
			],
		];
		/*
		 * handling sect
		 *
		 *
		 *
		 */
		$info = "";
		$invalidStatus = false;
		$status = $this->requestManager->getStatus();
		if ( $status === MirahezeRequestsStatus::STATUS_COMPLETE ) {
			$invalidStatus = true;
		}
		$blocks = $this->requestManager->getIpBlocks();
		if ( $blocks ) {
			$info .= $codex->message()->setContentText( implode( " ", $blocks ) )->build()->getHtml();
		}
		if ( $authority->isAllowed( 'handle-requestaccount' ) ) {
			$formDescriptor += [
				'test-info' => [
					'type' => 'info',
					'default' => $info,
					'raw' => true,
					'section' => 'handling',
				],
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
					'disabled' => $invalidStatus,
					'section' => 'handling',
				],
				'submit-decline-reason' => [
					'type' => 'text',
					'label-message' => 'mirahezerequests-label-decline-reason',
					'section' => 'handling',
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

	protected function submitForm(
		array $formData,
		HTMLForm $form,
	): void {
		$username = $formData['username'];
		$email = $formData['email'];
		$out = $this->context->getOutput();

		if ( isset( $formData['submit-accept'] ) ) {
			$this->requestManager->executeJob( $username, $email );
			$out->addHTML( Html::successBox(
				$this->context->msg( 'mirahezerequests-request-accepted' )->escaped()
			) );

			$this->requestManager->setStatus( self::STATUS_COMPLETE );
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$this->requestManager->executeJob( $username, $email );
			$out->addHTML( Html::successBox(
				$this->context->msg( 'mirahezerequests-request-declined' )->escaped()
			) );

			$subjectMessage = wfMessage( 'requestaccount-declined-email-title' );
			$bodyMessage = wfMessage( 'requestaccount-declined-email-text', $formData['submit-decline-reason'] );

			// TODO: email rejection

			$this->requestManager->setStatus( self::STATUS_DECLINED );
		}
	}
}
