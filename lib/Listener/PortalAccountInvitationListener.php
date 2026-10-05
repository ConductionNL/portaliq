<?php

/**
 * Portaliq PortalAccountInvitationListener
 *
 * Answers `PortalAccountInvitationRequestedEvent`: mints the one-time secret
 * of the waiting account and mails it to the account's address. The secret
 * goes out by mail only.
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Portaliq\Event\PortalAccountInvitationRequestedEvent;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Mails the invitation an app asked for, and answers the result slot.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class PortalAccountInvitationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param WaitingAccountInvitation $invitations Mints the secret.
	 * @param PortalIdentityMailer $mailer Mails it inside its link.
	 * @param LoggerInterface $logger Records a refusal's cause, never the secret.
	 */
	public function __construct(
		private readonly WaitingAccountInvitation $invitations,
		private readonly PortalIdentityMailer $mailer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof PortalAccountInvitationRequestedEvent) === false) {
			return;
		}

		try {
			$issued = $this->invitations->issue(subjectRef: $event->getSubjectRef(), appId: $event->getAppId());
		} catch (Throwable $exception) {
			$this->logger->error('Portal account invitation failed', ['exception' => get_class($exception)]);
			$issued = null;
		}

		if ($issued === null) {
			$event->answer(PortalAccountInvitationRequestedEvent::REFUSED);
			return;
		}

		$sent = $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_ACCOUNT_INVITATION,
			email: $issued['email'],
			secret: $issued['token'],
			organisation: $issued['organisation']
		);
		if ($sent === false) {
			$event->answer(PortalAccountInvitationRequestedEvent::NOT_SENT, $issued['expiresAt']);
			return;
		}

		$event->answer(PortalAccountInvitationRequestedEvent::SENT, $issued['expiresAt']);
	}//end handle()
}//end class
