<?php

/**
 * Portaliq Traffic Geo First Download Job
 *
 * The one-off half of portal-traffic-visitors-and-geo: queued by the
 * resolver the first time a measuring portal wants a region and no
 * database exists, so the download happens in the background and never
 * inside a collector request.
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
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fetch the geography database once, then leave the schedule to the
 * monthly job.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */
class TrafficGeoFirstDownloadJob extends QueuedJob {

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time    Testable clock (job base class).
	 * @param GeoRefreshService $refresh Does the work.
	 * @param LoggerInterface   $logger  The logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly GeoRefreshService $refresh,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()


	/**
	 * Refresh the database, catching everything.
	 *
	 * @param mixed $argument Unused; the first download carries no payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		try {
			$this->refresh->refresh();
		} catch (Throwable $failure) {
			$this->logger->error('[TrafficGeoFirstDownloadJob] download failed: ' . $failure->getMessage(), ['exception' => $failure]);
		}
	}//end run()
}//end class
