<?php

/**
 * Portaliq domain re-verification job
 *
 * Twice a day, re-checks the verification record of every verified domain of
 * every published portal, and unbinds a domain whose record stays gone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Cms\PortalDomainVerifier;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-checks verified domains; a removed record eventually unbinds its domain.
 *
 * With a 12-hour interval and PortalDomainVerifier::UNBIND_AFTER at three, a
 * removed record unbinds its domain after about a day and a half. One
 * portal's failure does not stop the others.
 *
 * @spec openspec/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
 */
class DomainReverificationJob extends TimedJob {

	private const INTERVAL = 43200;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time     The clock.
	 * @param PortalResolver       $portals  Lists the published portals.
	 * @param PortalDomainVerifier $verifier Re-checks a portal's domains.
	 * @param LoggerInterface      $logger   Records what was unbound.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalResolver $portals,
		private readonly PortalDomainVerifier $verifier,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Re-check the domains of every published portal.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-scoping-and-auth/spec.md#requirement-a-custom-domain-must-be-verified-before-it-serves
	 */
	protected function run($argument): void {
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			try {
				$result = $this->verifier->reverify(slug: $slug);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: re-checking the domains of a portal failed', ['portal' => $slug, 'reason' => $e->getMessage()]);
				continue;
			}

			foreach (($result['unbound'] ?? []) as $hostname) {
				$this->logger->warning('Portaliq: a domain was unbound because its verification record is gone', ['portal' => $slug, 'hostname' => $hostname]);
			}
		}
	}//end run()
}//end class
