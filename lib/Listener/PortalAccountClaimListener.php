<?php

/**
 * Portaliq PortalAccountClaimListener
 *
 * Answers `PortalAccountClaimRequestedEvent` by writing
 * `claims.<appId>.<claimName>` on the named account, with the app id taken
 * from the dispatching app's own context.
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

use OCA\Portaliq\Event\PortalAccountClaimRequestedEvent;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes an app's claim on a portal account, and answers the result slot.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountClaimListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Writes the claim.
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
		if (($event instanceof PortalAccountClaimRequestedEvent) === false) {
			return;
		}

		if ($event->getAppId() === '') {
			$event->answer('refused');
			return;
		}

		try {
			$written = $this->accounts->claim(
				subjectRef: $event->getSubjectRef(),
				appId: $event->getAppId(),
				claimName: $event->getClaimName(),
				value: $event->getValue()
			);
		} catch (Throwable $exception) {
			$this->logger->error('Portal account claim failed', ['exception' => $exception]);
			$event->answer('refused');
			return;
		}

		if ($written === false) {
			$event->answer('refused');
			return;
		}

		$event->answer('ok');
	}//end handle()
}//end class
