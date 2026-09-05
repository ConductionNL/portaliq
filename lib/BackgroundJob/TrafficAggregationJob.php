<?php

/**
 * Portaliq Traffic Aggregation Job
 *
 * The scheduled half of portal-traffic-analytics: every fifteen minutes,
 * rebuild each portal's open daily rollups from the raw events and purge
 * the raw events past their retention. The work itself lives in
 * TrafficAggregationService; this class is the cron contract around it.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\TrafficAggregationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recompute the traffic rollups on a schedule.
 *
 * Time-insensitive: a rollup that is fifteen minutes late is still right,
 * and the cron runner should spend its sensitive slots on mail. Parallel
 * runs are refused: two runs recomputing the same day would race on the
 * upsert and the loser's numbers would be the ones that stay.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */
class TrafficAggregationJob extends TimedJob {

	/**
	 * Seconds between runs: fifteen minutes.
	 */
	public const INTERVAL = 900;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory              $time        Testable clock (job base class).
	 * @param TrafficAggregationService $aggregation Does the work.
	 * @param LoggerInterface           $logger      The logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TrafficAggregationService $aggregation,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()


	/**
	 * Rebuild the open rollups and purge expired raw events.
	 *
	 * Every failure is caught and logged: the job never lets an exception
	 * escape to the cron runner, which would mark it failed and back off.
	 *
	 * @param mixed $argument Unused; the TimedJob contract requires the
	 *                        parameter and a recurring rebuild carries no payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		try {
			$this->aggregation->run();
		} catch (Throwable $failure) {
			$this->logger->error('[TrafficAggregationJob] aggregation failed: ' . $failure->getMessage(), ['exception' => $failure]);
		}
	}//end run()
}//end class
