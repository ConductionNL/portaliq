<?php

/**
 * Portaliq Mail Log Retention Job (mail-templates-admin-screen)
 *
 * Once a day, removes the mail log rows older than 90 days.
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\Mail\MailLog;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Removes expired mail log rows.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t05
 */
class MailLogRetentionJob extends TimedJob {
	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time The clock the scheduler uses.
	 * @param MailLog      $log  The log to clean.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly MailLog $log,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Run once a day.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t05
	 */
	protected function run($argument): void {
		$this->log->purgeExpired();
	}//end run()
}//end class
