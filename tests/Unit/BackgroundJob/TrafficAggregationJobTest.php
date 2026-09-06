<?php

/**
 * Unit tests for TrafficAggregationJob.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\TrafficAggregationJob;
use OCA\Portaliq\Service\TrafficAggregationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The cron contract around the aggregation service.
 */
class TrafficAggregationJobTest extends TestCase {

	/**
	 * A job over a service double.
	 *
	 * @param TrafficAggregationService $service The service.
	 * @param LoggerInterface|null      $logger  The logger.
	 *
	 * @return TrafficAggregationJob The job.
	 */
	private function job(TrafficAggregationService $service, ?LoggerInterface $logger = null): TrafficAggregationJob {
		return new TrafficAggregationJob(
			$this->createMock(ITimeFactory::class),
			$service,
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end job()


	/**
	 * Invoke the protected run().
	 *
	 * @param TrafficAggregationJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(TrafficAggregationJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()


	/**
	 * Every fifteen minutes, time-insensitive, never in parallel.
	 *
	 * @return void
	 */
	public function testTheScheduleIsFifteenMinutesInsensitiveAndSerial(): void {
		$job = $this->job($this->createMock(TrafficAggregationService::class));

		$this->assertSame(900, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
		$this->assertFalse($job->getAllowParallelRuns());
	}//end testTheScheduleIsFifteenMinutesInsensitiveAndSerial()


	/**
	 * run() runs the service.
	 *
	 * @return void
	 */
	public function testRunRunsTheAggregation(): void {
		$service = $this->createMock(TrafficAggregationService::class);
		$service->expects($this->once())->method('run')->willReturn(['portals' => 1, 'days' => 2, 'purged' => 0]);

		$this->runJob($this->job($service));
	}//end testRunRunsTheAggregation()


	/**
	 * A failure is logged and swallowed: the cron runner never sees it.
	 *
	 * @return void
	 */
	public function testAFailureIsLoggedNotThrown(): void {
		$service = $this->createMock(TrafficAggregationService::class);
		$service->method('run')->willThrowException(new RuntimeException('OpenRegister is down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('OpenRegister is down'));

		$this->runJob($this->job($service, $logger));
	}//end testAFailureIsLoggedNotThrown()
}//end class
