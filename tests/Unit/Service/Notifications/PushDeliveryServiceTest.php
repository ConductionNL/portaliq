<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use DateTimeImmutable;
use OCA\Portaliq\Service\Notifications\PendingPushService;
use OCA\Portaliq\Service\Notifications\PushDeliveryService;
use OCA\Portaliq\Service\Notifications\PushSenderInterface;
use OCA\Portaliq\Service\Notifications\QuietHoursPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-an-emergency-push-bypasses-quiet-hours-unconditionally
 */
class PushDeliveryServiceTest extends TestCase {

	public function testAPushOutsideQuietHoursDeliversImmediately(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->method('isQuietNow')->willReturn(false);

		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->once())->method('send')->with('guardian-1', 'Title', 'Body')->willReturn(true);

		$pending = $this->createMock(PendingPushService::class);
		$pending->expects($this->never())->method('queue');

		$service = new PushDeliveryService($quietHours, $sender, $pending, $this->createMock(LoggerInterface::class));

		$this->assertTrue($service->deliver('guardian-1', 'Title', 'Body'));
	}//end testAPushOutsideQuietHoursDeliversImmediately()

	public function testAPushDuringQuietHoursIsQueuedNotDropped(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->method('isQuietNow')->willReturn(true);
		$quietHours->method('windowEnd')->willReturn(new DateTimeImmutable('2026-09-27 07:00'));

		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');

		$pending = $this->createMock(PendingPushService::class);
		$pending->expects($this->once())->method('queue')->with('guardian-1', 'Title', 'Body', $this->isInstanceOf(DateTimeImmutable::class))->willReturn(true);

		$service = new PushDeliveryService($quietHours, $sender, $pending, $this->createMock(LoggerInterface::class));

		$this->assertTrue($service->deliver('guardian-1', 'Title', 'Body'));
	}//end testAPushDuringQuietHoursIsQueuedNotDropped()

	public function testAnEmergencyPushBypassesQuietHoursUnconditionally(): void {
		$quietHours = $this->createMock(QuietHoursPolicy::class);
		$quietHours->expects($this->never())->method('isQuietNow');
		$quietHours->expects($this->never())->method('windowEnd');

		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->once())->method('send')->with('guardian-1', 'Alarm', 'Evacuate')->willReturn(true);

		$pending = $this->createMock(PendingPushService::class);
		$pending->expects($this->never())->method('queue');

		$service = new PushDeliveryService($quietHours, $sender, $pending, $this->createMock(LoggerInterface::class));

		$this->assertTrue($service->deliver('guardian-1', 'Alarm', 'Evacuate', emergency: true));
	}//end testAnEmergencyPushBypassesQuietHoursUnconditionally()

	public function testReturnsFalseForAnEmptySubjectRef(): void {
		$service = new PushDeliveryService(
			$this->createMock(QuietHoursPolicy::class),
			$this->createMock(PushSenderInterface::class),
			$this->createMock(PendingPushService::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertFalse($service->deliver('', 'Title', 'Body'));
	}//end testReturnsFalseForAnEmptySubjectRef()
}//end class
