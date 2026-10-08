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
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
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
}//end class
