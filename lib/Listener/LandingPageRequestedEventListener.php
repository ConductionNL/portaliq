<?php

/**
 * Portaliq LandingPageRequestedEventListener
 *
 * Wires `LandingPageRequestedEvent` (ADR-041) to
 * `LandingPageProvisioningService`. Registered in `Application::register()`.
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
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Portaliq\Event\LandingPageRequestedEvent;
use OCA\Portaliq\Service\LandingPageProvisioningService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles `LandingPageRequestedEvent` synchronously.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
 */
class LandingPageRequestedEventListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LandingPageProvisioningService $service Validates and provisions the request.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LandingPageProvisioningService $service,
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
	 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-a-contributing-app-requests-a-landing-page-via-a-typed-event
	 */
	public function handle(Event $event): void {
		if (($event instanceof LandingPageRequestedEvent) === false) {
			return;
		}

		try {
			$this->service->provision(event: $event);
		} catch (Throwable $e) {
			// A thrown exception here would propagate out of dispatchTyped()
			// and into the CALLER's own request — a platform fault in
			// Portaliq must not crash the requesting app. Report it as a
			// handled, machine-readable failure instead.
			$this->logger->error(
				'Portaliq: landing page provisioning failed unexpectedly',
				['reason' => $e->getMessage()]
			);
			$event->setError('write_failed');
			$event->setHandled(true);
		}
	}//end handle()
}//end class
