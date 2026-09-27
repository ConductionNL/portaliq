<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use DateTimeImmutable;
use OCA\Portaliq\Service\Notifications\PendingPushService;
use OCA\Portaliq\Service\Notifications\PushSenderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @spec openspec/changes/push-notifications-quiet-hours/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */
class PendingPushServiceTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	private function fakeObjectService(array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array<string,mixed>>
			 */
			public array $saved = [];

			public function __construct(
				private array $rows,
			) {
			}//end __construct()

			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rows;
			}//end findAll()

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saved[] = $object;
				return $object;
			}//end saveObject()
		};
	}//end fakeObjectService()

	private function container(object $objectService): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $id) => ($id === self::OS) ? $objectService : throw new RuntimeException('no service'));
		return $container;
	}//end container()

	public function testQueueSavesAPendingRow(): void {
		$objectService = $this->fakeObjectService([]);
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');

		$service = new PendingPushService($this->container($objectService), $sender, $this->createMock(LoggerInterface::class));
		$result = $service->queue('guardian-1', 'Title', 'Body', new DateTimeImmutable('2026-09-27 07:00'));

		$this->assertTrue($result);
		$this->assertSame('guardian-1', $objectService->saved[0]['subjectRef']);
		$this->assertFalse($objectService->saved[0]['delivered']);
	}//end testQueueSavesAPendingRow()

	public function testDeliverDueDeliversOnlyDueUndeliveredRows(): void {
		$rows = [
			['id' => 'p1', 'subjectRef' => 'guardian-1', 'title' => 'A', 'body' => 'a', 'deliverAfter' => '2026-09-26T07:00:00+00:00', 'delivered' => false],
			['id' => 'p2', 'subjectRef' => 'guardian-2', 'title' => 'B', 'body' => 'b', 'deliverAfter' => '2026-09-28T07:00:00+00:00', 'delivered' => false],
			['id' => 'p3', 'subjectRef' => 'guardian-3', 'title' => 'C', 'body' => 'c', 'deliverAfter' => '2026-09-25T07:00:00+00:00', 'delivered' => true],
		];
		$objectService = $this->fakeObjectService($rows);

		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->once())->method('send')->with('guardian-1', 'A', 'a')->willReturn(true);

		$service = new PendingPushService($this->container($objectService), $sender, $this->createMock(LoggerInterface::class));
		$delivered = $service->deliverDue(new DateTimeImmutable('2026-09-26 12:00'));

		$this->assertSame(1, $delivered);
		$this->assertTrue($objectService->saved[0]['delivered']);
	}//end testDeliverDueDeliversOnlyDueUndeliveredRows()

	public function testDeliverDueReturnsZeroWhenNothingIsDue(): void {
		$rows = [['id' => 'p1', 'subjectRef' => 'guardian-1', 'title' => 'A', 'body' => 'a', 'deliverAfter' => '2026-09-28T07:00:00+00:00', 'delivered' => false]];
		$objectService = $this->fakeObjectService($rows);

		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');

		$service = new PendingPushService($this->container($objectService), $sender, $this->createMock(LoggerInterface::class));
		$delivered = $service->deliverDue(new DateTimeImmutable('2026-09-26 12:00'));

		$this->assertSame(0, $delivered);
	}//end testDeliverDueReturnsZeroWhenNothingIsDue()
}//end class
