<?php

/**
 * Portaliq Action Reminder Job (personal-action-list)
 *
 * Daily: the reminders three days before an action's end date.
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
 * @spec openspec/changes/personal-action-list/tasks.md#t04
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\ActionReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

/**
 * Runs the action reminders once a day.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t04
 */
class ActionReminderJob extends TimedJob {
	private const INTERVAL = 86400;

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory          $time      The scheduler's clock.
	 * @param ActionReminderService $reminders Sends the reminders.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ActionReminderService $reminders,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()

	/**
	 * Send the reminders due today.
	 *
	 * @param mixed $argument The job argument, unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t04
	 */
	protected function run($argument): void {
		$this->reminders->remindDue();
	}//end run()
}//end class
