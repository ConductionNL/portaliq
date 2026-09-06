<?php

/**
 * Unit tests for TrafficReportJob.
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

use OCA\Portaliq\BackgroundJob\TrafficReportJob;
use OCA\Portaliq\Service\TrafficReportService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The cron contract: hourly, insensitive, serial; the work delegated;
 * a failure logged, never thrown.
 */
class TrafficReportJobTest extends TestCase {

	/**
	 * The job over a report service double.
	 *
	 * @param TrafficReportService $service The service.
	 * @param LoggerInterface|null $logger  The logger.
	 *
	 * @return TrafficReportJob The job.
	 */
	private function job(TrafficReportService $service, ?LoggerInterface $logger = null): TrafficReportJob {
		return new TrafficReportJob(
			$this->createMock(ITimeFactory::class),
			$service,
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end job()


	/**
	 * @return void
	 */
	public function testTheScheduleIsHourlyInsensitiveAndSerial(): void {
		$job = $this->job($this->createMock(TrafficReportService::class));

		$this->assertSame(3600, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
		$this->assertFalse($job->getAllowParallelRuns());
	}//end testTheScheduleIsHourlyInsensitiveAndSerial()


	/**
	 * @return void
	 */
	public function testRunDelegatesAndAFailureIsLoggedNotThrown(): void {
		$service = $this->createMock(TrafficReportService::class);
		$service->expects($this->once())->method('run')->willReturn(['reports' => 1, 'alerts' => 0]);
		(new ReflectionMethod($this->job($service), 'run'))->invoke($this->job($service), null);

		$failing = $this->createMock(TrafficReportService::class);
		$failing->method('run')->willThrowException(new RuntimeException('mailer down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringContains('mailer down'));
		(new ReflectionMethod(TrafficReportJob::class, 'run'))->invoke($this->job($failing, $logger), null);
	}//end testRunDelegatesAndAFailureIsLoggedNotThrown()
}//end class
