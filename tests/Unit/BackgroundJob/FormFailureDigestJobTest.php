<?php

/**
 * FormFailureDigestJobTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

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

	/**
	 * The clock's fixed time in these tests.
	 */
	private const NOW = 1764313200;

	/**
	 * The digest is asked to run at the clock's time.
	 *
	 * @return void
	 */
	public function testTheDigestRunsAtTheClocksTime(): void {
		$digest = $this->createMock(originalClassName: FormFailureDigest::class);
		$digest->expects($this->once())->method('run')->with(self::NOW);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->runJob(job: $this->job(digest: $digest, logger: $logger));
	}//end testTheDigestRunsAtTheClocksTime()

	/**
	 * A digest that throws is logged with the time, and the run ends normally.
	 *
	 * @return void
	 */
	public function testAFailingDigestIsLoggedNotThrown(): void {
		$digest = $this->createMock(originalClassName: FormFailureDigest::class);
		$digest->method('run')->willThrowException(new RuntimeException(message: 'mail down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with(
				'Portaliq: the digest of failed form submits did not run',
				$this->callback(
					callback: static fn (array $context): bool => $context['at'] === self::NOW
						&& $context['exception'] instanceof RuntimeException
				)
			);

		$this->runJob(job: $this->job(digest: $digest, logger: $logger));
	}//end testAFailingDigestIsLoggedNotThrown()

	/**
	 * The job is scheduled once an hour.
	 *
	 * @return void
	 */
	public function testTheJobRunsHourly(): void {
		$job = $this->job(
			digest: $this->createMock(originalClassName: FormFailureDigest::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: 3600, actual: $job->getInterval());
	}//end testTheJobRunsHourly()

	/**
	 * The job over a clock fixed at NOW.
	 *
	 * @param FormFailureDigest $digest The digest.
	 * @param LoggerInterface   $logger The logger.
	 *
	 * @return FormFailureDigestJob
	 */
	private function job(FormFailureDigest $digest, LoggerInterface $logger): FormFailureDigestJob {
		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		return new FormFailureDigestJob(time: $time, digest: $digest, logger: $logger);
	}//end job()

	/**
	 * Run the job's protected run() as the cron does.
	 *
	 * @param FormFailureDigestJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(FormFailureDigestJob $job): void {
		$run = new ReflectionMethod(objectOrMethod: $job, method: 'run');
		$run->invoke($job, null);
	}//end runJob()
}//end class
