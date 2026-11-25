<?php

/**
 * Portaliq Form Failure Digest Job
 *
 * Hourly: sends the daily digest of failed form submits once it is due
 * (from 07:00 server time, once a day).
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Intake\FormFailureDigest;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hourly check; the digest itself goes out once a day from 07:00.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class FormFailureDigestJob extends TimedJob {

	/**
	 * Once an hour, so the digest goes out in the first hour from 07:00.
	 */
	public const INTERVAL = 3600;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory      $time   The clock.
	 * @param FormFailureDigest $digest Sends the digest when it is due.
	 * @param LoggerInterface   $logger Logs a run that failed.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FormFailureDigest $digest,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Send the digest when it is due.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	protected function run($argument): void {
		try {
			$this->digest->run();
		} catch (Throwable $failure) {
			$this->logger->error('[FormFailureDigestJob] digest failed: ' . $failure->getMessage(), ['exception' => $failure]);
		}
	}//end run()
}//end class
