<?php

/**
 * Portaliq ClaimScopeCheck
 *
 * Asks the app that owns an account claim whether the claim may scope for the
 * account's organisation, through PortalClaimScopeEvent. When the event cannot
 * be dispatched the claim is refused, so a claim never scopes unchecked.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Event\PortalClaimScopeEvent;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Whether the owning app lets a claim scope for an organisation.
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
 */
final class ClaimScopeCheck {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the event dispatcher.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether no listener refused the claim.
	 *
	 * @param PortalClaimScopeEvent $event The claim, its value and the account's organisation.
	 *
	 * @return bool False when a listener refused it or the event could not be dispatched.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function admits(PortalClaimScopeEvent $event): bool {
		try {
			$dispatcher = $this->container->get(IEventDispatcher::class);
			if ($dispatcher instanceof IEventDispatcher === false) {
				$this->logger->warning('Portaliq: no event dispatcher, account claim refused', ['claim' => $event->getClaimName()]);
				return false;
			}

			$dispatcher->dispatchTyped($event);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: account claim check failed, claim refused', ['claim' => $event->getClaimName(), 'reason' => $e->getMessage()]);
			return false;
		}

		return $event->isRefused() === false;
	}//end admits()
}//end class
