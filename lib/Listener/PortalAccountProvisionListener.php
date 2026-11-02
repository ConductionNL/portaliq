<?php

/**
 * Portaliq PortalAccountProvisionListener
 *
 * Answers `PortalAccountProvisionRequestedEvent` by provisioning a pending
 * portal account and filling the event's result slot. A refusal is a word in
 * the slot, never an exception across the app boundary: the dispatching app
 * must be able to carry on without knowing how portaliq is built.
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Portaliq\Event\PortalAccountProvisionRequestedEvent;
use OCA\Portaliq\Service\Identity\NextcloudAccountProvisioner;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisions the account an app asked for, and answers the result slot.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountProvisionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Provisions the account.
	 * @param LoggerInterface $logger Records a refusal's cause.
	 * @param NextcloudAccountProvisioner|null $nextcloud Writes an active
	 *        `nextcloud`-mode account (an-app-provisions-a-nextcloud-account).
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly LoggerInterface $logger,
		private readonly ?NextcloudAccountProvisioner $nextcloud = null,
	) {
	}//end __construct()

	/**
	 * Handle the event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof PortalAccountProvisionRequestedEvent) === false) {
			return;
		}

		if ($event->getAppId() === '') {
			// The app id is the dispatcher's own context. Without it nothing
			// can be attributed, so nothing is written.
			$event->refuse('unknown_app');
			return;
		}

		if ($event->getNextcloudUid() !== '') {
			$this->provisionNextcloud(event: $event);
			return;
		}

		try {
			$account = $this->accounts->provision(
				audience: $event->getAudience(),
				organisation: $event->getOrganisation(),
				identityType: $event->getIdentityType(),
				identityRef: $event->getIdentityRef(),
				email: $event->getEmail(),
				verifiedEmail: $event->isVerifiedEmail(),
				provisionedBy: $event->getAppId(),
				displayName: $event->getDisplayName()
			);
		} catch (Throwable $exception) {
			$this->logger->error('Portal account provisioning failed', ['exception' => $exception]);
			$event->refuse('unavailable');
			return;
		}

		if ($account === null) {
			$event->refuse('refused');
			return;
		}

		$event->answer($account['subjectRef'], $account['status']);
	}//end handle()

	/**
	 * Answer a request for an active `nextcloud`-mode account.
	 *
	 * @param PortalAccountProvisionRequestedEvent $event The request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/an-app-provisions-a-nextcloud-account/specs/portal-identity-space/spec.md#requirement-an-app-may-provision-an-active-account-for-a-nextcloud-user
	 */
	private function provisionNextcloud(PortalAccountProvisionRequestedEvent $event): void {
		if ($this->nextcloud === null) {
			$event->refuse('unavailable');
			return;
		}

		try {
			$answer = $this->nextcloud->provision(
				appId: $event->getAppId(),
				uid: $event->getNextcloudUid(),
				portal: $event->getPortal(),
				audience: $event->getAudience(),
				organisation: $event->getOrganisation(),
				email: $event->getEmail(),
				displayName: $event->getDisplayName()
			);
		} catch (Throwable $exception) {
			$this->logger->error('Portal account provisioning failed', ['exception' => $exception]);
			$event->refuse('unavailable');
			return;
		}

		if (is_string($answer) === true) {
			$event->refuse($answer);
			return;
		}

		$event->answer($answer['subjectRef'], $answer['status']);
	}//end provisionNextcloud()
}//end class
