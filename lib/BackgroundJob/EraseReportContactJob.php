<?php

/**
 * Portaliq Erase Report Contact Job
 *
 * Once a day, erases the contact address of every error report on a
 * publication that was closed more than 30 days ago
 * (publication-error-reports-and-withheld-notices REQ-PER-002). An open
 * report keeps its address, so the owner can still reply.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
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
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Service\Publication\PublicationReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Erases the contact addresses of reports closed 30 days ago.
 *
 * @spec openspec/changes/publication-error-reports-and-withheld-notices/tasks.md#2-error-reports
 */
class EraseReportContactJob extends TimedJob {
	/**
	 * Once a day.
	 */
	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory             $time    The clock the scheduler uses.
	 * @param PortalResolverInterface  $portals Every published portal.
	 * @param PublicationReportService $reports Erases the addresses.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalResolverInterface $portals,
		private readonly PublicationReportService $reports,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Erase the expired addresses on every portal.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publication-error-reports-and-withheld-notices/specs/portal-federated-search/spec.md#requirement-a-report-reaches-the-named-owner-req-per-002
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates the signature.
	 */
	protected function run($argument): void {
		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true) {
				$this->reports->eraseExpiredContacts(portal: $portal);
			}
		}
	}//end run()
}//end class
