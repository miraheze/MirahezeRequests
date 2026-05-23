<?php

namespace Miraheze\MirahezeRequests;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Exception\UserNotLoggedIn;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use Miraheze\MirahezeRequests\Services\MirahezeRequestsValidator;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;

class RenameWikiRequestViewer implements MirahezeRequestsStatus {

	public function __construct(
		private readonly Config $config,
		private readonly MirahezeRequestsValidator $validator,
		private readonly IContextSource $context,
		private readonly RequestAccountManager $requestManager
	) {
	}

	public function getFormDescriptor(): array {
		$user = $this->context->getUser();
		$authority = $this->context->getAuthority();

		if (
			$this->requestManager->isPrivate( forced: false ) &&
			$user->getName() !== $this->requestManager->getRequester()->getName() &&
			!$authority->isAllowed( 'view-private-renamewiki-requests' )
		) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'renamewiki-private' )->escaped() )
			);

			return [];
		}

		if ( $this->requestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::errorBox( $this->context->msg( 'renamewiki-request-locked' )->escaped() )
			);
		}

		$this->context->getOutput()->enableOOUI();

		$formDescriptor = [
			'oldwiki' => [
				'label-message' => 'renamewiki-label-oldwiki',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getOldWiki(),
			],
			'newwiki' => [
				'label-message' => 'renamewiki-label-newwiki',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getNewWiki(),
			],
			'requester' => [
				'label-message' => 'renamewiki-label-requester',
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
				'label-message' => 'renamewiki-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'renamewiki-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'renamewiki-label-' . $this->requestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 6,
				'readonly' => true,
				'label-message' => 'renamewiki-label-reason',
				'default' => $this->requestManager->getReason(),
				'raw' => true,
				'cssclass' => 'ext-renamewiki-infuse',
				'section' => 'details',
			],
		];

		foreach ( $this->requestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 6,
				'label-message' => [
					'renamewiki-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->timeanddate( $comment['timestamp'], true ),
				],
				'default' => $comment['comment'],
			];
		}

		if (
			$authority->isAllowed( 'handle-renamewiki-requests' ) ||
			$user->getActorId() === $this->requestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 6,
					'label-message' => 'renamewiki-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this->validator, 'isValidComment' ],
					'disabled' => $this->requestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'buttonlabel-message' => 'renamewiki-label-add-comment',
					'disabled' => $this->requestManager->isLocked(),
					'section' => 'comments',
				],
				'edit-oldwiki' => [
					'label-message' => 'renamewiki-label-oldwiki',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getOldWiki(),
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-newwiki' => [
					'label-message' => 'renamewiki-label-newwiki',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getNewWiki(),
					'validation-callback' => [ $this->validator, 'isInvalidDatabase' ],
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 6,
					'label-message' => 'renamewiki-label-reason',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getReason(),
					'validation-callback' => [ $this->validator, 'isValidReason' ],
					'disabled' => $this->requestManager->isLocked(),
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'buttonlabel-message' => 'renamewiki-label-edit-request',
					'disabled' => $this->requestManager->isLocked(),
					'section' => 'editing',
				],
			];
		}

		if ( $authority->isAllowed( 'handle-renamewiki-requests' ) ) {
			$validRequest = true;
			$status = $this->requestManager->getStatus();

			$info = new MessageWidget( [
				'label' => new HtmlSnippet(
						$this->context->msg( 'requestcustomdomain-info-groups',
							$this->requestManager->getRequester()->getName(),
							$this->requestManager->getTarget(),
							$this->context->getLanguage()->commaList(
								$this->requestManager->getUserGroupsFromTarget()
							)
						)->escaped(),
					),
				'type' => 'notice',
			] );

			if ( $this->requestManager->isPrivate( forced: false ) ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet( $this->context->msg( 'renamewiki-info-request-private' )->escaped() ),
					'type' => 'warning',
				] );
			}

			if ( $this->requestManager->getRequester()->getBlock() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'renamewiki-info-requester-blocked',
								$this->requestManager->getRequester()->getName(),
								WikiMap::getCurrentWikiId()
							)->escaped()
						),
					'type' => 'warning',
				] );
			}

			if ( $this->requestManager->getRequester()->isLocked() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'renamewiki-info-requester-locked',
								$this->requestManager->getRequester()->getName()
							)->escaped()
						),
					'type' => 'error',
				] );

				$validRequest = false;
				if ( $status === self::STATUS_PENDING || $status === self::STATUS_INPROGRESS ) {
					$status = self::STATUS_DECLINED;
				}
			}

			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => $info,
					'raw' => true,
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'renamewiki-label-lock',
					'default' => $this->requestManager->isLocked(),
					'section' => 'handling',
				],
			];

			if ( $authority->isAllowed( 'view-private-renamewiki-requests' ) ) {
				$formDescriptor += [
					'handle-private' => [
						'type' => 'check',
						'label-message' => 'renamewiki-label-private',
						'default' => $this->requestManager->isPrivate( forced: false ),
						'disabled' => $this->requestManager->isPrivate( forced: true ),
						'section' => 'handling',
					],
				];
			}

			if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
				$formDescriptor += [
					'handle-status' => [
						'type' => 'select',
						'label-message' => 'renamewiki-label-update-status',
						'options-messages' => array_unique( [
							'renamewiki-label-' . $status => $status,
							'renamewiki-label-pending' => self::STATUS_PENDING,
							'renamewiki-label-inprogress' => self::STATUS_INPROGRESS,
							'renamewiki-label-complete' => self::STATUS_COMPLETE,
						] ),
						'default' => $status,
						'disabled' => !$validRequest,
						'cssclass' => 'ext-renamewiki-infuse',
						'section' => 'handling',
					],
					'submit-handle' => [
						'type' => 'submit',
						'buttonlabel-message' => 'htmlform-submit',
						'section' => 'handling',
					],
				];
			}

			if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
				$validStatus = true;
				if (
					$status === self::STATUS_COMPLETE ||
					$status === self::STATUS_INPROGRESS ||
					$status === self::STATUS_STARTING
				) {
					$validStatus = false;
				}

				$formDescriptor += [
					'handle-comment' => [
						'type' => 'textarea',
						'rows' => 6,
						'label-message' => 'renamewiki-label-status-updated-comment',
						'section' => 'handling',
					],
					'submit-start' => [
						'type' => 'submit',
						'buttonlabel-message' => 'renamewiki-label-start-renamewiki',
						'disabled' => !$validRequest || !$validStatus,
						'section' => 'handling',
					],
					'submit-decline' => [
						'type' => 'submit',
						'flags' => [ 'destructive', 'primary' ],
						'buttonlabel-message' => 'renamewiki-label-decline-renamewiki',
						'disabled' => !$validStatus || $status === self::STATUS_DECLINED,
						'section' => 'handling',
					],
				];
			} else {
				$formDescriptor += [
					'handle-status' => [
						'type' => 'select',
						'label-message' => 'renamewiki-label-update-status',
						'options-messages' => [
							'renamewiki-label-pending' => self::STATUS_PENDING,
							'renamewiki-label-inprogress' => self::STATUS_INPROGRESS,
							'renamewiki-label-complete' => self::STATUS_COMPLETE,
							'renamewiki-label-declined' => self::STATUS_DECLINED,
						],
						'default' => $status,
						'disabled' => !$validRequest,
						'cssclass' => 'ext-renamewiki-infuse',
						'section' => 'handling',
					],
					'handle-comment' => [
						'type' => 'textarea',
						'rows' => 6,
						'label-message' => 'renamewiki-label-status-updated-comment',
						'section' => 'handling',
					],
					'submit-handle' => [
						'type' => 'submit',
						'buttonlabel-message' => 'htmlform-submit',
						'section' => 'handling',
					],
				];
			}
		}

		return $formDescriptor;
	}

	public function getForm( int $requestID ): ?OOUIHTMLFormTabs {
		$this->requestManager->getById( $requestID );
		$out = $this->context->getOutput();
		if ( $requestID === 0 || !$this->requestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'renamewiki-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.renamewiki.oouiform' ] );
		$out->addModuleStyles( [ 'ext.renamewiki.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new OOUIHTMLFormTabs( $formDescriptor, $this->context, 'renamewiki-section' );

		$htmlForm->setId( 'renamewiki-request-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form );
			}
		);

		return $htmlForm;
	}

	/** @throws UserNotLoggedIn */
	protected function submitForm(
		array $formData,
		HTMLForm $form
	): void {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$out = $form->getContext()->getOutput();
		$session = $form->getRequest()->getSession();

		if ( isset( $formData['submit-comment'] ) ) {
			if ( $session->get( 'previous_posted_comment' ) !== $formData['comment'] ) {
				$session->set( 'previous_posted_comment', $formData['comment'] );
				$this->requestManager->addComment( $formData['comment'], $user );
				$out->addHTML( Html::successBox( $this->context->msg( 'renamewiki-comment-success' )->escaped() ) );
				return;
			}

			$out->addHTML( Html::errorBox( $this->context->msg( 'renamewiki-duplicate-comment' )->escaped() ) );
			return;
		}

		$session->remove( 'previous_posted_comment' );

		if ( isset( $formData['submit-edit'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->requestManager->getReason() !== $formData['edit-reason'] ) {
				$changes[] = $this->context->msg( 'renamewiki-request-edited-reason' )->plaintextParams(
					$this->requestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->requestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->requestManager->getOldWiki() !== $formData['edit-oldwiki'] ) {
				$changes[] = $this->context->msg( 'renamewiki-request-edited-oldwiki' )->plaintextParams(
					$this->requestManager->getOldWiki(),
					$formData['edit-oldwiki']
				)->escaped();

				$this->requestManager->setOldWiki( $formData['edit-oldwiki'] );
			}

			if ( $this->requestManager->getNewWiki() !== $formData['edit-newwiki'] ) {
				$changes[] = $this->context->msg(
					'renamewiki-request-edited-newwiki',
					$this->requestManager->getNewWiki(),
					$formData['edit-newwiki']
				)->escaped();

				$this->requestManager->setNewWiki( $formData['edit-newwiki'] );
			}

			if ( !$changes ) {
				$this->requestManager->endAtomic( __METHOD__ );

				$out->addHTML( Html::errorBox( $this->context->msg( 'renamewiki-no-changes' )->escaped() ) );

				return;
			}

			if ( $this->requestManager->getStatus() === self::STATUS_DECLINED ) {
				$this->requestManager->setStatus( self::STATUS_PENDING );

				$comment = $this->context->msg( 'renamewiki-request-reopened', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->logStatusUpdate( $comment, self::STATUS_PENDING, $user );

				$this->requestManager->addComment( $comment, User::newSystemUser( 'RenameWiki Extension' ) );

				$this->requestManager->sendNotification(
					$comment, 'renamewiki-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'renamewiki-request-edited', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->addComment( $comment, User::newSystemUser( 'RenameWiki Extension' ) );
			}

			$this->requestManager->endAtomic( __METHOD__ );

			$out->addHTML( Html::successBox( $this->context->msg( 'renamewiki-edit-success' )->escaped() ) );

			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );
			$changes = [];

			if ( $this->requestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->requestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->requestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->requestManager->isPrivate( forced: false ) !== (bool)$formData['handle-private']
			) {
				$changes[] = $this->requestManager->isPrivate( forced: false ) ?
					'public' : 'private';

				$this->requestManager->setPrivate( (int)$formData['handle-private'] );
			}

			if (
				!isset( $formData['handle-status'] ) ||
				$this->requestManager->getStatus() === $formData['handle-status']
			) {
				$this->requestManager->endAtomic( __METHOD__ );

				if ( !$changes ) {
					$out->addHTML( Html::errorBox( $this->context->msg( 'renamewiki-no-changes' )->escaped() ) );
					return;
				}

				if ( in_array( 'private', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'renamewiki-success-private' )->escaped() )
					);
				}

				if ( in_array( 'public', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'renamewiki-success-public' )->escaped() )
					);
				}

				if ( in_array( 'locked', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'renamewiki-success-locked' )->escaped() )
					);
				}

				if ( in_array( 'unlocked', $changes ) ) {
					$out->addHTML(
						Html::successBox( $this->context->msg( 'renamewiki-success-unlocked' )->escaped() )
					);
				}

				return;
			}

			if ( isset( $formData['handle-status'] ) ) {
				if ( $this->config->get( ConfigNames::EnableAutomatedJob ) ) {
					$formData['handle-comment'] = '';
				}

				$this->handleStatusUpdate( $formData, $user );
				$this->requestManager->endAtomic( __METHOD__ );
				return;
			}
		}

		if (
			$this->requestManager->getStatus() === self::STATUS_COMPLETE ||
			$this->requestManager->getStatus() === self::STATUS_INPROGRESS ||
			$this->requestManager->getStatus() === self::STATUS_STARTING
		) {
			$out->addHTML( Html::errorBox(
				$this->context->msg( 'renamewiki-status-conflict' )->escaped()
			) );

			return;
		}

		if ( isset( $formData['submit-decline'] ) ) {
			$formData['handle-status'] = self::STATUS_DECLINED;
			$this->requestManager->startAtomic( __METHOD__ );
			$this->handleStatusUpdate( $formData, $user );
			$this->requestManager->endAtomic( __METHOD__ );
			return;
		}

		if ( isset( $formData['submit-start'] ) ) {
			if ( $this->requestManager->getStatus() === self::STATUS_COMPLETE ) {
				// Don't rerun a job that is already completed.
				return;
			}

			$this->requestManager->setStatus( self::STATUS_STARTING );
			$this->requestManager->executeJob( $user->getName() );
			$out->addHTML( Html::successBox(
				$this->context->msg( 'renamewiki-renamewiki-started' )->escaped()
			) );
		}
	}

	private function handleStatusUpdate( array $formData, User $user ): void {
		$this->requestManager->setStatus( $formData['handle-status'] );
		$statusMessage = $this->context->msg( 'renamewiki-label-' . $formData['handle-status'] )
			->inContentLanguage()
			->text();

		$comment = $this->context->msg( 'renamewiki-status-updated', mb_strtolower( $statusMessage ) )
			->inContentLanguage()
			->escaped();

		if ( $formData['handle-comment'] ) {
			$commentUser = User::newSystemUser( 'RenameWiki Status Update' );

			$comment .= "\n" . $this->context->msg( 'renamewiki-comment-given', $user->getName() )
				->inContentLanguage()
				->escaped();

			$comment .= ' ' . $formData['handle-comment'];
		}

		$this->requestManager->addComment( $comment, $commentUser ?? $user );
		$this->requestManager->logStatusUpdate(
			$formData['handle-comment'], $formData['handle-status'], $user
		);

		$this->requestManager->sendNotification(
			$comment, 'renamewiki-request-status-update', $user
		);

		$this->context->getOutput()->addHTML( Html::successBox(
			$this->context->msg( 'renamewiki-status-updated-success' )->escaped()
		) );
	}
}
