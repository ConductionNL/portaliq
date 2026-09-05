<?php

/**
 * Unit tests for TrafficGeoRefreshJob and TrafficGeoFirstDownloadJob.
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

use OCA\Portaliq\BackgroundJob\TrafficGeoFirstDownloadJob;
use OCA\Portaliq\BackgroundJob\TrafficGeoRefreshJob;
use OCA\Portaliq\Service\Traffic\Geo\GeoRefreshService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Both jobs call the one refresh and let nothing escape to the cron
 * runner.
 */
class TrafficGeoJobsTest extends TestCase {

	/**
	 * The monthly job: thirty days, time insensitive, no parallel runs.
	 *
	 * @return void
	 */
	public function testTheMonthlyJobIsThirtyDaysAndInsensitive(): void {
		$service = $this->createMock(GeoRefreshService::class);
		$service->expects($this->once())->method('refresh')->willReturn(['status' => 'refreshed', 'provider' => 'dbip', 'message' => '']);

		$job = new TrafficGeoRefreshJob($this->createMock(ITimeFactory::class), $service, $this->createMock(LoggerInterface::class));
		$this->assertSame(30 * 86400, $job->getInterval());
		$this->assertFalse($job->isTimeSensitive());
		$this->assertFalse($job->getAllowParallelRuns());

		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testTheMonthlyJobIsThirtyDaysAndInsensitive()


	/**
	 * A refresh that throws is logged by both jobs, never rethrown.
	 *
	 * @return void
	 */
	public function testAThrowingRefreshIsLoggedNotRethrown(): void {
		$service = $this->createMock(GeoRefreshService::class);
		$service->method('refresh')->willThrowException(new RuntimeException('disk full'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('error')->with($this->stringContains('disk full'));

		$monthly = new TrafficGeoRefreshJob($this->createMock(ITimeFactory::class), $service, $logger);
		(new ReflectionMethod($monthly, 'run'))->invoke($monthly, null);

		$first = new TrafficGeoFirstDownloadJob($this->createMock(ITimeFactory::class), $service, $logger);
		$this->assertFalse($first->getAllowParallelRuns());
		(new ReflectionMethod($first, 'run'))->invoke($first, null);
	}//end testAThrowingRefreshIsLoggedNotRethrown()
}//end class
