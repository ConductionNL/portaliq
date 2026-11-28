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
	 * @param ITimeFactory      $time   The clock; its time is what the digest compares with 07:00.
	 * @param FormFailureDigest $digest Sends the digest when it is due.
	 * @param LoggerInterface   $logger Logs a run that failed.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FormFailureDigest $digest,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Send the digest when it is due; a failure is logged, never thrown.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t12
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		$now = $this->time->getTime();
		try {
			$this->digest->run(now: $now);
		} catch (Throwable $failure) {
			$this->logger->warning('Portaliq: the digest of failed form submits did not run', ['exception' => $failure, 'at' => $now]);
		}
	}//end run()
}//end class
