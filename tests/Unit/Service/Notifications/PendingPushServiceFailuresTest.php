<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The failure paths of PendingPushService: no OpenRegister, a queue or read
 * that throws, rows the delivery cannot use, a push that does not leave, and
 * a delivered row whose mark cannot be saved.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Notifications;

use DateTimeImmutable;
use OCA\Portaliq\Service\Notifications\PendingPushService;
use OCA\Portaliq\Service\Notifications\PushSenderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Notifications\PendingPushService
 *
 * @spec openspec/specs/guardian-push-notifications/spec.md#requirement-a-due-deferred-push-is-delivered-by-the-background-job-an-undue-one-is-left-alone
 */
class PendingPushServiceFailuresTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The logger of the service under test.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * An object service whose reads answer `$rows` and whose saves throw when asked.
	 *
	 * @param mixed $rows      What findAll answers, or a Throwable it throws.
	 * @param bool  $saveFails Whether saveObject throws.
	 *
	 * @return object
	 */
	private function objectService(mixed $rows, bool $saveFails = false): object {
		return new class($rows, $saveFails) {
			/**
			 * @var array<int, array<string,mixed>>
			 */
			public array $saved = [];

			/**
			 * @var array<int, string|null>
			 */
			public array $uuids = [];

			public function __construct(
				private mixed $rows,
				private bool $saveFails,
			) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}

			/**
			 * @param array<string,mixed> $object
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->saveFails === true) {
					throw new RuntimeException('database is read-only');
				}

				$this->saved[] = $object;
				$this->uuids[] = $uuid;
				return $object;
			}
		};
	}

	/**
	 * The service over this object service (null: OpenRegister is absent).
	 *
	 * @param object|null         $objectService The object service.
	 * @param PushSenderInterface $sender        The push transport.
	 *
	 * @return PendingPushService
	 */
	private function service(?object $objectService, PushSenderInterface $sender): PendingPushService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id) => ($objectService !== null && $id === self::OS) ? $objectService : throw new RuntimeException('no service')
		);
		$this->logger = $this->createMock(LoggerInterface::class);

		return new PendingPushService($container, $sender, $this->logger);
	}

	/**
	 * A due, undelivered row.
	 *
	 * @param array<string,mixed> $extra Fields to set or override.
	 *
	 * @return array<string,mixed>
	 */
	private static function due(array $extra = []): array {
		return $extra + ['subjectRef' => 'guardian-1', 'title' => 'A', 'body' => 'a', 'deliverAfter' => '2026-09-26T07:00:00+00:00', 'delivered' => false];
	}

	public function testWithoutOpenRegisterNothingIsQueuedOrDelivered(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');
		$service = $this->service(null, $sender);

		$this->assertFalse($service->queue('guardian-1', 'T', 'B', new DateTimeImmutable('2026-09-27 07:00')));
		$this->assertSame(0, $service->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
	}

	public function testAQueueThatCannotBeSavedIsLoggedAndFalse(): void {
		$service = $this->service($this->objectService([], true), $this->createMock(PushSenderInterface::class));
		$this->logger->expects($this->once())->method('warning')->with('Portaliq: pending push queue failed', ['reason' => 'database is read-only']);

		$this->assertFalse($service->queue('guardian-1', 'T', 'B', new DateTimeImmutable('2026-09-27 07:00')));
	}

	public function testAReadThatFailsDeliversNothingAndSaysSo(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');
		$service = $this->service($this->objectService(new RuntimeException('timeout')), $sender);
		$this->logger->expects($this->once())->method('warning')->with('Portaliq: pending push read failed', ['reason' => 'timeout']);

		$this->assertSame(0, $service->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
	}

	public function testAReadThatAnswersNoListDeliversNothing(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');

		$this->assertSame(0, $this->service($this->objectService(null), $sender)->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
	}

	public function testRowsWithoutAMomentOrNotRowsAreSkipped(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->never())->method('send');
		$objectService = $this->objectService(['not a row', self::due(['id' => 'p1', 'deliverAfter' => ''])]);

		$this->assertSame(0, $this->service($objectService, $sender)->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
		$this->assertSame([], $objectService->saved);
	}

	public function testAPushThatDoesNotLeaveStaysUndelivered(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->once())->method('send')->willReturn(false);
		$objectService = $this->objectService([self::due(['id' => 'p1'])]);

		$this->assertSame(0, $this->service($objectService, $sender)->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
		$this->assertSame([], $objectService->saved, 'it is tried again on the next run');
	}

	public function testARowWithoutAnIdIsSentButNotCounted(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->expects($this->once())->method('send')->with('guardian-1', 'A', 'a')->willReturn(true);
		$objectService = $this->objectService([self::due(['@self' => ['name' => 'no id']])]);

		$this->assertSame(0, $this->service($objectService, $sender)->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
		$this->assertSame([], $objectService->saved);
	}

	public function testTheSelfIdMarksTheRowDeliveredWithoutItsEnvelope(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->method('send')->willReturn(true);
		$objectService = $this->objectService([self::due(['@self' => ['id' => 'uuid-7']])]);

		$this->assertSame(1, $this->service($objectService, $sender)->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
		$this->assertSame(['uuid-7'], $objectService->uuids);
		$this->assertTrue($objectService->saved[0]['delivered']);
		$this->assertArrayNotHasKey('@self', $objectService->saved[0]);
	}

	public function testAMarkThatCannotBeSavedIsLoggedAndNotCounted(): void {
		$sender = $this->createMock(PushSenderInterface::class);
		$sender->method('send')->willReturn(true);
		$service = $this->service($this->objectService([self::due(['id' => 'p1'])], true), $sender);
		$this->logger->expects($this->once())->method('warning')->with('Portaliq: pending push mark-delivered failed', ['reason' => 'database is read-only']);

		$this->assertSame(0, $service->deliverDue(new DateTimeImmutable('2026-09-27 07:00')));
	}
}
