<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\MailLogRetentionJob;
use OCA\Portaliq\Kernel\Contracts\MailLogInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The daily job that removes expired mail log rows.
 *
 * @spec openspec/changes/archive/2026-11-25-mail-templates-admin-screen/tasks.md#t05
 */
#[CoversClass(MailLogRetentionJob::class)]
class MailLogRetentionJobTest extends TestCase {
	/**
	 * The job is scheduled once a day.
	 *
	 * @return void
	 */
	public function testTheJobRunsOnceADay(): void {
		$log = $this->createMock(MailLogInterface::class);
		$log->expects($this->never())->method('purgeExpired');

		$job = new MailLogRetentionJob($this->createMock(ITimeFactory::class), $log);

		$this->assertSame(86400, $job->getInterval());
	}//end testTheJobRunsOnceADay()

	/**
	 * A run purges the expired rows exactly once, with the log's own
	 * defaults, and never queues a retry.
	 *
	 * @return void
	 */
	public function testARunPurgesTheExpiredRowsOnce(): void {
		$log = $this->createMock(MailLogInterface::class);
		$log->expects($this->once())->method('purgeExpired')->with()->willReturn(12);
		$log->expects($this->never())->method('queueRetry');

		$job = new MailLogRetentionJob($this->createMock(ITimeFactory::class), $log);

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testARunPurgesTheExpiredRowsOnce()
}//end class
