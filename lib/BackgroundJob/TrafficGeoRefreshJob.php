<?php

/**
 * Portaliq Traffic Geo Refresh Job
 *
 * The monthly half of portal-traffic-visitors-and-geo: every thirty days,
 * fetch a fresh geography database from the configured provider. The
 * work itself lives in GeoRefreshService; this class is the cron
 * contract around it.
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
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refresh the geography database monthly.
 *
 * Time-insensitive: a database that is a day late is still a database,
 * and the download is the one long transfer this app makes. Parallel
 * runs are refused: two downloads racing on the same file would waste
 * the bandwidth twice.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
 */
class TrafficGeoRefreshJob extends TimedJob {

	/**
	 * Seconds between runs: thirty days.
	 */
	public const INTERVAL = 30 * 86400;

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
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()


	/**
	 * Refresh the database, catching everything.
	 *
	 * @param mixed $argument Unused; a recurring refresh carries no payload.
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
			$this->logger->error('[TrafficGeoRefreshJob] refresh failed: ' . $failure->getMessage(), ['exception' => $failure]);
		}
	}//end run()
}//end class
