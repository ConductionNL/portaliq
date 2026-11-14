<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\PortalRegisterContext;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The traffic store's reads of events and daily rollups, deleting a rollup,
 * and the edges of append(): nothing to write, a save that answers no list,
 * a register that is not set up and a failing write.
 */
#[CoversClass(TrafficEventStore::class)]
class TrafficEventStoreReadsTest extends TestCase {

	/**
	 * What the logger received.
	 *
	 * @var list<array{string, string, array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The schemas the context was applied with.
	 *
	 * @var list<string>
	 */
	private array $applied = [];

	protected function setUp(): void {
		$this->logged = [];
		$this->applied = [];
	}//end setUp()

	/**
	 * A store over $objectService.
	 *
	 * @param object|null $objectService The ObjectService double, or null for none.
	 * @param bool        $applies       Whether the register context applies.
	 *
	 * @return TrafficEventStore
	 */
	private function store(?object $objectService, bool $applies = true): TrafficEventStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		}
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(function (object $service, string $schemaSlug) use ($applies): bool {
			$this->applied[] = $schemaSlug;
			return $applies;
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['error', 'warning', 'info'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->logged[] = [$level, $message, $context];
			});
		}

		return new TrafficEventStore($container, $logger, $context);
	}//end store()

	/**
	 * An ObjectService double whose findAll() answers $rows and records the configs.
	 *
	 * @param array<int, mixed> $rows The rows.
	 *
	 * @return object
	 */
	private static function reader(array $rows): object {
		return new class ($rows) {
			public array $configs = [];

			public function __construct(private array $rows) {
			}

			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				$this->configs[] = $config + ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $this->rows;
			}
		};
	}//end reader()

	public function testEventsReceivedSinceAreReadAsStoredFromTheEventSchema(): void {
		$row = ['@self' => ['id' => 'x'], 'path' => '/a', 'empty' => '', 'n' => 0];
		$reader = self::reader([$row]);

		$this->assertSame([$row], $this->store($reader)->receivedSince('2026-11-01T00:00:00Z'));
		$this->assertSame(
			[['filters' => ['receivedAt' => ['gte' => '2026-11-01T00:00:00Z']], 'limit' => 1000, 'offset' => 0, '_rbac' => false, '_multitenancy' => false]],
			$reader->configs
		);
		$this->assertSame(['portalTrafficEvent', 'portalTrafficEvent'], $this->applied);
	}//end testEventsReceivedSinceAreReadAsStoredFromTheEventSchema()

	public function testTheDailyRollupIsTheOneWithoutASegment(): void {
		$reader = self::reader([['segment' => 'mobile', 'views' => 3], ['segment' => '  ', 'views' => 9], ['views' => 1]]);

		$this->assertSame(['segment' => '  ', 'views' => 9], $this->store($reader)->findDaily('gem', '2026-11-12'));
		$this->assertSame(['portal' => 'gem', 'date' => '2026-11-12'], $reader->configs[0]['filters']);
		$this->assertSame(1000, $reader->configs[0]['limit']);
	}//end testTheDailyRollupIsTheOneWithoutASegment()

	public function testWithOnlySegmentRollupsThereIsNoDailyRollup(): void {
		$this->assertNull($this->store(self::reader([['segment' => 'mobile']]))->findDaily('gem', '2026-11-12'));
	}//end testWithOnlySegmentRollupsThereIsNoDailyRollup()

	public function testRollupsBetweenTwoDaysAreOneSegmentsOldestFirst(): void {
		$reader = self::reader([
			['date' => '2026-11-01', 'views' => 1],
			['date' => '2026-11-01', 'segment' => 'mobile', 'views' => 2],
			['date' => '2026-11-02', 'segment' => ' mobile ', 'views' => 3],
		]);
		$store = $this->store($reader);

		$this->assertSame([['date' => '2026-11-01', 'views' => 1]], $store->dailyBetween('gem', '2026-11-01', '2026-11-30'));
		$this->assertSame(
			[['date' => '2026-11-01', 'segment' => 'mobile', 'views' => 2], ['date' => '2026-11-02', 'segment' => ' mobile ', 'views' => 3]],
			$store->dailyBetween('gem', '2026-11-01', '2026-11-30', 'mobile')
		);
		$this->assertSame(
			['filters' => ['portal' => 'gem', 'date' => ['gte' => '2026-11-01', 'lte' => '2026-11-30']], 'limit' => 1000, 'offset' => 0, 'sort' => ['date' => 'ASC'], '_rbac' => false, '_multitenancy' => false],
			$reader->configs[0]
		);
	}//end testRollupsBetweenTwoDaysAreOneSegmentsOldestFirst()

	public function testAFailedReadIsLoggedAndEmpty(): void {
		$failing = new class {
			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				throw new RuntimeException('db down');
			}
		};

		$this->assertSame([], $this->store($failing)->receivedSince('2026-11-01'));
		$this->assertSame([['error', 'Portaliq: traffic read failed', ['schema' => 'portalTrafficEvent', 'reason' => 'db down']]], $this->logged);
	}//end testAFailedReadIsLoggedAndEmpty()

	public function testNothingIsReadWithoutOpenRegisterOrARegister(): void {
		$reader = self::reader([['x' => 1]]);

		$this->assertSame([], $this->store(null)->receivedSince('2026-11-01'));
		$this->assertSame([], $this->store($reader, false)->dailyBetween('gem', 'a', 'b'));
		$this->assertSame([], $reader->configs);
	}//end testNothingIsReadWithoutOpenRegisterOrARegister()

	public function testARollupIsDeletedWithoutRbacOrMultitenancy(): void {
		$service = new class {
			public array $deleted = [];

			public function deleteObject(...$args): void {
				$this->deleted[] = $args;
			}
		};

		$this->assertTrue($this->store($service)->deleteDaily('u-1'));
		$this->assertSame(
			[['uuid' => 'u-1', 'register' => 'portaliq', 'schema' => 'portalTrafficDaily', '_rbac' => false, '_multitenancy' => false]],
			$service->deleted
		);
		$this->assertSame(['portalTrafficDaily'], $this->applied);
	}//end testARollupIsDeletedWithoutRbacOrMultitenancy()

	public function testNoRollupIsDeletedWithoutAnIdARegisterOrOpenRegister(): void {
		$service = new class {
			public array $deleted = [];

			public function deleteObject(...$args): void {
				$this->deleted[] = $args;
			}
		};

		$this->assertFalse($this->store($service)->deleteDaily(''));
		$this->assertFalse($this->store($service, false)->deleteDaily('u-1'));
		$this->assertFalse($this->store(null)->deleteDaily('u-1'));
		$this->assertSame([], $service->deleted);
		$this->assertSame([], $this->logged);
	}//end testNoRollupIsDeletedWithoutAnIdARegisterOrOpenRegister()

	public function testAFailedDeleteIsLoggedAndFalse(): void {
		$service = new class {
			public function deleteObject(...$args): void {
				throw new RuntimeException('locked');
			}
		};

		$this->assertFalse($this->store($service)->deleteDaily('u-1'));
		$this->assertSame([['error', 'Portaliq: traffic rollup delete failed', ['uuid' => 'u-1', 'reason' => 'locked']]], $this->logged);
	}//end testAFailedDeleteIsLoggedAndFalse()

	public function testAppendingNothingWritesNothing(): void {
		$service = new class {
			public array $calls = [];

			public function appendObjectsRaw(...$args): int {
				$this->calls[] = $args;
				return 0;
			}
		};

		$this->assertSame(0, $this->store($service)->append([]));
		$this->assertSame([], $this->logged);
		$this->assertSame([], $service->calls);
	}//end testAppendingNothingWritesNothing()

	public function testASaveThatAnswersNoListCountsTheRecords(): void {
		$service = new class {
			public function saveObjects(...$args): ?array {
				return null;
			}
		};

		$this->assertSame(2, $this->store($service)->append([['a' => 1], ['a' => 2]]));
	}//end testASaveThatAnswersNoListCountsTheRecords()

	public function testTheSavePathWritesNothingWithoutARegister(): void {
		$service = new class {
			public array $calls = [];

			public function saveObjects(...$args): array {
				$this->calls[] = $args;
				return [];
			}
		};

		$this->assertSame(0, $this->store($service, false)->append([['a' => 1]]));
		$this->assertSame(['portalTrafficEvent'], $this->applied);
		$this->assertSame([], $service->calls);
	}//end testTheSavePathWritesNothingWithoutARegister()

	public function testAFailedAppendIsLoggedAndZero(): void {
		$service = new class {
			public function appendObjectsRaw(...$args): int {
				throw new RuntimeException('disk full');
			}
		};

		$this->assertSame(0, $this->store($service)->append([['a' => 1]]));
		$this->assertSame(['error', 'Portaliq: traffic append failed', ['reason' => 'disk full']], $this->logged[1]);
	}//end testAFailedAppendIsLoggedAndZero()

	public function testWithoutOpenRegisterNothingIsAppended(): void {
		$this->assertSame(0, $this->store(null)->append([['a' => 1]]));
	}//end testWithoutOpenRegisterNothingIsAppended()
}//end class
