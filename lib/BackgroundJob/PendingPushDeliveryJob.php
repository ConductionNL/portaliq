<?php

/**
 * Pending Push Delivery Job
 *
 * The scheduled half of push-notifications-quiet-hours: every 5 minutes,
 * deliver every deferred push whose quiet-hours window has ended. The work
 * lives in PendingPushService; this class is the cron contract around it,
 * mirroring TrafficReportJob's existing shape in this app.
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
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use DateTimeImmutable;
use OCA\Portaliq\Service\Notifications\PendingPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deliver due deferred pushes, every 5 minutes.
 *
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */
class PendingPushDeliveryJob extends TimedJob {
	/**
	 * Seconds between runs: five minutes — frequent enough that a deferred
	 * push does not sit long past its window's end.
	 */
	public const INTERVAL = 300;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Testable clock (job base class).
	 * @param PendingPushService $pending Does the work.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PendingPushService $pending,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Deliver everything due.
	 *
	 * @param mixed $argument Unused; the TimedJob contract requires the
	 *                        parameter and a recurring run carries no payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		try {
			$this->pending->deliverDue(now: new DateTimeImmutable());
		} catch (Throwable $failure) {
			$this->logger->error('[PendingPushDeliveryJob] delivery failed: ' . $failure->getMessage(), ['exception' => $failure]);
		}
	}//end run()
}//end class
