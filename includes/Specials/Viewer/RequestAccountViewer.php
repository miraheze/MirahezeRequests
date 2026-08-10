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
		if ( !$this->requestManager->exists() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'mirahezerequests-notfound' )->escaped() )
			);
			return [];
		}

		$codex = new Codex();
		$authority = $this->context->getAuthority();
		$isHandler = $authority->isAllowed( 'handle-requestaccount' );

		// Handlers can view any request; the person who filed the
		// request can view their own (read-only, no handling controls).
		// Without this, the link in the "your request has been filed"
		// success message 403s for anyone who isn't a handler.
		$isOwner = $this->context->getUser()->equals( $this->requestManager->getRequester() );

		if ( !$isHandler && !$isOwner ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'mirahezerequests-nopermission' )->escaped() )
			);
			return [];
		}

		$canSeeIp = $this->requestManager->canSeeIp( $this->context->getUser() );

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

		if ( $canSeeIp ) {
			$formDescriptor['ip'] = [
				'label-message' => 'mirahezerequests-label-ip',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->requestManager->getIp(),
			];
		}

		$isFinalized = $this->requestManager->isFinalized();

		if ( $isFinalized ) {
			$formDescriptor['resolved-info'] = [
				'type' => 'info',
				'default' => $this->buildResolvedInfoHtml(),
				'raw' => true,
				'section' => 'details',
			];
		} else {
			$formDescriptor['status'] = [
				'label-message' => 'mirahezerequests-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'mirahezerequests-status-' . $this->requestManager->getStatus()
				)->text(),
			];
		}

		if ( $isHandler ) {
			$info = '';
			$blocks = $this->requestManager->getIpBlocks();
			if ( $blocks ) {
				$info .= $codex->message()->setContentText( implode( "\n", $blocks ) )->build()->getHtml();
			}

			$formDescriptor['handle-info'] = [
				'type' => 'info',
				'default' => $info,
				'raw' => true,
				'section' => 'handling',
			];

			if ( !$isFinalized ) {
				$formDescriptor += [
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
						'validation-callback' => [ $this, 'isValidDeclineReason' ],
					],
				];
			}
		}

		return $formDescriptor;
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
				$this->context->getLanguage()->timeanddate( $completedTimestamp, true )
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
	): bool {
		$out = $this->context->getOutput();

		if ( $this->requestManager->isFinalized() ) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'mirahezerequests-status-conflict' )->escaped()
			) );
			return true;
		}

		$username = $this->requestManager->getUsername();
		$requestTitle = SpecialPage::getTitleFor( 'RequestAccountQueue', (string)$this->requestManager->getId() );

		if ( isset( $formData['submit-accept'] ) ) {
			// The job verifies the outcome and sets the real final
			// status; this is just an in-flight marker so the request
			// can't be actioned again while it's being processed.
			$this->requestManager->setStatus( MirahezeRequestsStatus::Starting->value );
			$this->requestManager->executeJob( $this->context->getUser() );

			$logEntry = new ManualLogEntry( 'requestaccount', 'accept' );
			$logEntry->setPerformer( $this->context->getUser() );
			$logEntry->setTarget( $requestTitle );
			$logEntry->setParameters( [ '4::requestTarget' => $username ] );
			$logEntry->publish( $logEntry->insert() );

			$out->redirect( $requestTitle->getFullURL() );
			return true;
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$this->requestManager->sendDeclineEmail( $formData['submit-decline-reason'] );
			$this->requestManager->resolve(
				MirahezeRequestsStatus::Declined->value, $this->context->getUser(), $formData['submit-decline-reason']
			);

			$logEntry = new ManualLogEntry( 'requestaccount', 'decline' );
			$logEntry->setPerformer( $this->context->getUser() );
			$logEntry->setTarget( $requestTitle );
			$logEntry->setComment( $formData['submit-decline-reason'] );
			$logEntry->setParameters( [ '4::requestTarget' => $username ] );
			$logEntry->publish( $logEntry->insert() );

			$out->redirect( $requestTitle->getFullURL() );
		}

		return true;
	}
}
