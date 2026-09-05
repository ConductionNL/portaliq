<?php

/**
 * Portaliq Traffic Report Job
 *
 * The scheduled half of portal-traffic-reporting: once an hour, send the
 * reports whose period has ended and fire the alerts whose condition
 * holds. The work lives in TrafficReportService; this class is the cron
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\TrafficReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Send due reports and fire alerts, hourly.
 *
 * Hourly rather than daily: an `above` alert watches the current day and
 * is only useful if it fires within the hour the threshold was crossed.
 * A report is due once per period whatever the hour, so the extra runs
 * cost nothing. Time-insensitive, and never in parallel: two runs
 * deciding the same report is due would send it twice.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */
class TrafficReportJob extends TimedJob {

	/**
	 * Seconds between runs: one hour.
	 */
	public const INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory         $time    Testable clock (job base class).
	 * @param TrafficReportService $reports Does the work.
	 * @param LoggerInterface      $logger  The logger.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly TrafficReportService $reports,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()


	/**
	 * Send what is due and fire what holds.
	 *
	 * @param mixed $argument Unused; the TimedJob contract requires the
	 *                        parameter and a recurring run carries no payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		try {
			$this->reports->run();
		} catch (Throwable $failure) {
			$this->logger->error('[TrafficReportJob] reporting failed: ' . $failure->getMessage(), ['exception' => $failure]);
		}
	}//end run()
}//end class
