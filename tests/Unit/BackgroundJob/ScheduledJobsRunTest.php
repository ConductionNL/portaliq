<?php

/**
 * Unit tests for the scheduled jobs that only hand over to a service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\ActionReminderJob;
use OCA\Portaliq\BackgroundJob\MediaUsageJob;
use OCA\Portaliq\Service\ActionReminderService;
use OCA\Portaliq\Service\Cms\MediaUsage;
use OCA\Portaliq\Service\Plans\PlanEndReminderService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The daily reminder job sends the action and plan-end reminders; the hourly
 * media job refreshes the media usage.
 */
#[CoversClass(ActionReminderJob::class)]
#[CoversClass(MediaUsageJob::class)]
class ScheduledJobsRunTest extends TestCase {

	/**
	 * The reminder job runs daily and sends both kinds of reminder.
	 *
	 * @return void
	 */
	public function testTheReminderJobSendsBothReminders(): void {
		$reminders = $this->createMock(ActionReminderService::class);
		$reminders->expects($this->once())->method('remindDue');
		$plans = $this->createMock(PlanEndReminderService::class);
		$plans->expects($this->once())->method('remindDue');
		$job = new ActionReminderJob($this->createMock(ITimeFactory::class), $reminders, $plans);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(86400, $job->getInterval());
	}//end testTheReminderJobSendsBothReminders()

	/**
	 * The media job runs hourly and refreshes the usage.
	 *
	 * @return void
	 */
	public function testTheMediaJobRefreshesTheUsage(): void {
		$usage = $this->createMock(MediaUsage::class);
		$usage->expects($this->once())->method('refresh');
		$job = new MediaUsageJob($this->createMock(ITimeFactory::class), $usage);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);

		$this->assertSame(3600, $job->getInterval());
	}//end testTheMediaJobRefreshesTheUsage()
}//end class
