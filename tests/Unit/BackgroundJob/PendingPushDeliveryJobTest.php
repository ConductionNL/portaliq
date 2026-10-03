<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\BackgroundJob;

use OCA\Portaliq\BackgroundJob\PendingPushDeliveryJob;
use OCA\Portaliq\Service\Notifications\PendingPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */
class PendingPushDeliveryJobTest extends TestCase {

	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);
		return $time;
	}//end time()

	/**
	 * Invoke the protected run() method the way Nextcloud's job scheduler
	 * does — matches the pattern `TrafficReportJobTest` already uses.
	 */
	private function invokeRun(PendingPushDeliveryJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end invokeRun()

	public function testADuePendingPushIsDeliveredAndRemoved(): void {
		$pending = $this->createMock(PendingPushService::class);
		$pending->expects($this->once())->method('deliverDue')->willReturn(1);

		$job = new PendingPushDeliveryJob($this->time(), $pending, $this->createMock(LoggerInterface::class));

		$this->invokeRun($job);
	}//end testADuePendingPushIsDeliveredAndRemoved()

	public function testANotYetDuePendingPushIsLeftAlone(): void {
		$pending = $this->createMock(PendingPushService::class);
		$pending->method('deliverDue')->willReturn(0);

		$job = new PendingPushDeliveryJob($this->time(), $pending, $this->createMock(LoggerInterface::class));

		// No exception, no error — a zero-delivery run is a normal outcome.
		$this->invokeRun($job);
		$this->addToAssertionCount(1);
	}//end testANotYetDuePendingPushIsLeftAlone()

	public function testAFailureIsLoggedAndNeverThrown(): void {
		$pending = $this->createMock(PendingPushService::class);
		$pending->method('deliverDue')->willThrowException(new RuntimeException('boom'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$job = new PendingPushDeliveryJob($this->time(), $pending, $logger);

		$this->invokeRun($job);
	}//end testAFailureIsLoggedAndNeverThrown()
}//end class
