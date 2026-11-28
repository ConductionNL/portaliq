<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\FormFailureDigestJob;
use OCA\Portaliq\Service\Intake\FormFailureDigest;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The hourly job hands the clock's time to the digest, and a digest that
 * fails is logged, never thrown into the cron run.
 *
 * @covers \OCA\Portaliq\BackgroundJob\FormFailureDigestJob
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 * @spec openspec/changes/search-sort-by-relevance/tasks.md#t12
 */
final class FormFailureDigestJobTest extends TestCase {

	public function testTheDigestRunsAtTheClocksTime(): void {
		$digest = $this->createMock(FormFailureDigest::class);
		$digest->expects($this->once())->method('run')->with(1764313200);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->runJob($this->job($digest, $logger));
	}//end testTheDigestRunsAtTheClocksTime()

	public function testAFailingDigestIsLoggedNotThrown(): void {
		$digest = $this->createMock(FormFailureDigest::class);
		$digest->method('run')->willThrowException(new RuntimeException('mail down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('Portaliq: the digest of failed form submits did not run', $this->callback(
				static fn (array $context): bool => $context['at'] === 1764313200
					&& $context['exception'] instanceof RuntimeException
			));

		$this->runJob($this->job($digest, $logger));
	}//end testAFailingDigestIsLoggedNotThrown()

	public function testTheJobRunsHourly(): void {
		$job = $this->job($this->createMock(FormFailureDigest::class), $this->createMock(LoggerInterface::class));

		$this->assertSame(FormFailureDigestJob::INTERVAL, $job->getInterval());
		$this->assertSame(3600, FormFailureDigestJob::INTERVAL);
	}//end testTheJobRunsHourly()

	/**
	 * The job over a clock fixed at 1764313200.
	 *
	 * @param FormFailureDigest $digest The digest.
	 * @param LoggerInterface   $logger The logger.
	 *
	 * @return FormFailureDigestJob
	 */
	private function job(FormFailureDigest $digest, LoggerInterface $logger): FormFailureDigestJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1764313200);

		return new FormFailureDigestJob($time, $digest, $logger);
	}//end job()

	/**
	 * Run the job's protected run() as the cron does.
	 *
	 * @param FormFailureDigestJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(FormFailureDigestJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end runJob()
}//end class
