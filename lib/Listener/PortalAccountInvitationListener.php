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
 * @spec openspec/specs/portal-identity-space/spec.md
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
 * @spec openspec/specs/portal-identity-space/spec.md
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
	 * @spec openspec/specs/portal-identity-space/spec.md
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof PortalAccountInvitationRequestedEvent) === false) {
			return;
		}

		if ($event->getChannel() === PortalAccountInvitationRequestedEvent::CHANNEL_LETTER) {
			$this->answerCode(event: $event);
			return;
		}

		if ($event->getChannel() !== PortalAccountInvitationRequestedEvent::CHANNEL_MAIL) {
			$event->answer(PortalAccountInvitationRequestedEvent::REFUSED);
			return;
		}

		$this->answerMail(event: $event);
	}//end handle()

	/**
	 * Mail the invitation secret to the waiting account's address. The secret
	 * itself is never answered.
	 *
	 * @param PortalAccountInvitationRequestedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	private function answerMail(PortalAccountInvitationRequestedEvent $event): void {
		$issued = $this->issuedInvitation(event: $event);
		if ($issued === null) {
			$event->answer(PortalAccountInvitationRequestedEvent::REFUSED);
			return;
		}

		if ($this->mailed(issued: $issued) === false) {
			$event->answer(PortalAccountInvitationRequestedEvent::NOT_SENT, $issued['expiresAt']);
			return;
		}

		$event->answer(PortalAccountInvitationRequestedEvent::SENT, $issued['expiresAt']);
	}//end answerMail()

	/**
	 * Issue the invitation, or null when it may not be issued or the issue
	 * failed (logged by class only, never its message).
	 *
	 * @param PortalAccountInvitationRequestedEvent $event The event.
	 *
	 * @return array<string, mixed>|null
	 */
	private function issuedInvitation(PortalAccountInvitationRequestedEvent $event): ?array {
		try {
			return $this->invitations->issue(subjectRef: $event->getSubjectRef(), appId: $event->getAppId());
		} catch (Throwable $exception) {
			$this->logger->error('Portal account invitation failed', ['exception' => get_class($exception)]);
			return null;
		}
	}//end issuedInvitation()

	/**
	 * Mail an issued invitation's secret.
	 *
	 * @param array<string, mixed> $issued The issued invitation.
	 *
	 * @return bool Whether the mail left.
	 */
	private function mailed(array $issued): bool {
		return $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_ACCOUNT_INVITATION,
			email: $issued['email'],
			secret: $issued['token'],
			organisation: $issued['organisation']
		);
	}//end mailed()

	/**
	 * Answer the code for a paper letter. Nothing is mailed.
	 *
	 * @param PortalAccountInvitationRequestedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	private function answerCode(PortalAccountInvitationRequestedEvent $event): void {
		try {
			$issued = $this->invitations->issueCode(subjectRef: $event->getSubjectRef(), appId: $event->getAppId());
		} catch (Throwable $exception) {
			$this->logger->error('Portal account invitation code failed', ['exception' => get_class($exception)]);
			$issued = null;
		}

		if ($issued === null) {
			$event->answer(PortalAccountInvitationRequestedEvent::REFUSED);
			return;
		}

		$event->answer(PortalAccountInvitationRequestedEvent::CODE, $issued['expiresAt'], $issued['code']);
	}//end answerCode()
}//end class
