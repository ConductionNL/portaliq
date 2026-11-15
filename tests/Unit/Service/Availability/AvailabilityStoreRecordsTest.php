<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Availability;

use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The availability records in OpenRegister: daily summaries and outages per
 * portal, the open outage, saving and purging old records.
 */
#[CoversClass(AvailabilityStore::class)]
class AvailabilityStoreRecordsTest extends TestCase {

	/**
	 * What reached the logger.
	 *
	 * @var list<array{string, array<string, mixed>}>
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
	 * A store over $service.
	 *
	 * @param object|null $service The ObjectService double, or null for none.
	 * @param bool        $applies Whether the register context applies.
	 *
	 * @return AvailabilityStore
	 */
	private function store(?object $service, bool $applies = true): AvailabilityStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(new RuntimeException('no openregister'));
		} else {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($service);
		}
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturnCallback(function (object $objectService, string $schemaSlug) use ($applies): bool {
			$this->applied[] = $schemaSlug;
			return $applies;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});

		return new AvailabilityStore($container, $logger, $context);
	}//end store()

	/**
	 * An ObjectService double answering findAll() from $answer and recording every call.
	 *
	 * @param \Closure $answer Maps (filters, offset) to rows.
	 *
	 * @return object
	 */
	private static function service(\Closure $answer): object {
		return new class ($answer) {
			public array $found = [];
			public array $saved = [];
			public array $deleted = [];

			public function __construct(private \Closure $answer) {
			}

			public function findAll(array $config, bool $_rbac, bool $_multitenancy): mixed {
				$this->found[] = $config + ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return ($this->answer)($config['filters'], $config['offset']);
			}

			public function saveObject(...$args): void {
				$this->saved[] = $args;
			}

			public function deleteObject(...$args): void {
				if ($args['uuid'] === 'locked') {
					throw new RuntimeException('locked');
				}
				$this->deleted[] = $args;
			}
		};
	}//end service()

	public function testDailySummariesAreKeyedByDateInOrderForThePortalOnly(): void {
		$service = self::service(static fn (): array => [
			['portal' => 'gem', 'date' => '2026-11-03', 'up' => 99.1],
			['portal' => 'other', 'date' => '2026-11-02'],
			['portal' => 'gem', 'date' => '2026-11-01', 'up' => 100],
			['portal' => 'gem'],
			new class {
				public function jsonSerialize(): array {
					return ['portal' => 'gem', 'date' => '2026-11-02', 'up' => 98];
				}
			},
			'not a row',
		]);

		$days = $this->store($service)->dailyBetween('gem', '2026-11-01', '2026-11-30');

		$this->assertSame(['2026-11-01', '2026-11-02', '2026-11-03'], array_keys($days));
		$this->assertSame(98, $days['2026-11-02']['up']);
		$this->assertSame(
			[['filters' => ['portal' => 'gem', 'date' => ['gte' => '2026-11-01', 'lte' => '2026-11-30']], 'limit' => 500, 'offset' => 0, '_rbac' => false, '_multitenancy' => false]],
			$service->found
		);
		$this->assertSame(['portalAvailabilityDaily'], $this->applied);
	}//end testDailySummariesAreKeyedByDateInOrderForThePortalOnly()

	public function testOutagesAreThePortalsOwnOldestFirst(): void {
		$service = self::service(static fn (): array => [
			['portal' => 'gem', 'startedAt' => '2026-11-05T10:00:00Z'],
			['portal' => 'other', 'startedAt' => '2026-11-01T00:00:00Z'],
			['portal' => 'gem', 'startedAt' => '2026-11-02T08:00:00Z'],
		]);

		$this->assertSame(
			[['portal' => 'gem', 'startedAt' => '2026-11-02T08:00:00Z'], ['portal' => 'gem', 'startedAt' => '2026-11-05T10:00:00Z']],
			$this->store($service)->outagesBetween('gem', '2026-11-01', '2026-11-30')
		);
		$this->assertSame(['portal' => 'gem', 'startedAt' => ['gte' => '2026-11-01', 'lte' => '2026-11-30']], $service->found[0]['filters']);
	}//end testOutagesAreThePortalsOwnOldestFirst()

	public function testTheOpenOutageIsFoundOnALaterPage(): void {
		$closed = array_fill(0, 500, ['portal' => 'gem', 'endedAt' => '2026-11-01T00:00:00Z']);
		$service = self::service(static fn (array $filters, int $offset): array => $offset === 0 ? $closed : [
			['portal' => 'other', 'endedAt' => ''],
			['portal' => 'gem', 'endedAt' => ' ', 'id' => 'open-1'],
		]);

		$this->assertSame(['portal' => 'gem', 'endedAt' => ' ', 'id' => 'open-1'], $this->store($service)->openOutage('gem'));
		$this->assertSame([0, 500], array_column($service->found, 'offset'));
	}//end testTheOpenOutageIsFoundOnALaterPage()

	public function testWithoutAnOpenOutageTheSearchStopsAtAShortPage(): void {
		$service = self::service(static fn (): array => [['portal' => 'gem', 'endedAt' => 'x']]);

		$this->assertNull($this->store($service)->openOutage('gem'));
		$this->assertCount(1, $service->found);
	}//end testWithoutAnOpenOutageTheSearchStopsAtAShortPage()

	public function testARowIsSavedUnderItsUuidWithoutItsIdentifiers(): void {
		$service = self::service(static fn (): array => []);

		$this->assertTrue($this->store($service)->save('portalAvailabilityOutage', ['@self' => ['uuid' => 'u-1'], 'id' => 'i', 'uuid' => 'u-x', 'portal' => 'gem']));
		$this->assertTrue($this->store($service)->save('portalAvailabilityDaily', ['portal' => 'gem']));

		$this->assertSame(
			[
				['object' => ['portal' => 'gem'], 'register' => 'portaliq', 'schema' => 'portalAvailabilityOutage', 'uuid' => 'u-1', '_rbac' => false, '_multitenancy' => false],
				['object' => ['portal' => 'gem'], 'register' => 'portaliq', 'schema' => 'portalAvailabilityDaily', 'uuid' => null, '_rbac' => false, '_multitenancy' => false],
			],
			$service->saved
		);
	}//end testARowIsSavedUnderItsUuidWithoutItsIdentifiers()

	public function testNothingIsSavedWithoutOpenRegisterOrARegister(): void {
		$service = self::service(static fn (): array => []);

		$this->assertFalse($this->store(null)->save('portalAvailabilityDaily', ['portal' => 'gem']));
		$this->assertFalse($this->store($service, false)->save('portalAvailabilityDaily', ['portal' => 'gem']));
		$this->assertSame([], $service->saved);
	}//end testNothingIsSavedWithoutOpenRegisterOrARegister()

	public function testAFailedSaveIsLoggedAndFalse(): void {
		$service = new class {
			public function saveObject(...$args): void {
				throw new RuntimeException('invalid');
			}
		};

		$this->assertFalse($this->store($service)->save('portalAvailabilityDaily', ['portal' => 'gem']));
		$this->assertSame([['Portaliq: availability save failed', ['schema' => 'portalAvailabilityDaily', 'reason' => 'invalid']]], $this->logged);
	}//end testAFailedSaveIsLoggedAndFalse()

	public function testPurgingRemovesRecordsBeforeTheCutoffFromBothSchemas(): void {
		$service = self::service(static fn (array $filters): array => isset($filters['date']) === true ? [
			['date' => '2026-01-01', 'id' => 'd-old'],
			['date' => '2026-06-01', 'id' => 'd-new'],
			['date' => '2026-01-02'],
		] : [
			['startedAt' => '2026-01-05', '@self' => ['id' => 'o-old']],
			['startedAt' => '2026-01-06', 'uuid' => 'locked'],
		]);

		$this->assertSame(2, $this->store($service)->purgeBefore('2026-03-01'));
		$this->assertSame(['d-old', 'o-old'], array_column($service->deleted, 'uuid'));
		$this->assertSame(['portalAvailabilityDaily', 'portalAvailabilityOutage'], array_column($service->deleted, 'schema'));
		$this->assertSame([false, false], array_column($service->deleted, '_rbac'));
		$this->assertSame(['date' => ['lt' => '2026-03-01']], $service->found[0]['filters']);
		$this->assertSame(['startedAt' => ['lt' => '2026-03-01']], $service->found[1]['filters']);
		$this->assertSame([['Portaliq: availability delete failed', ['schema' => 'portalAvailabilityOutage', 'reason' => 'locked']]], $this->logged);
	}//end testPurgingRemovesRecordsBeforeTheCutoffFromBothSchemas()

	public function testReadsAreEmptyWithoutOpenRegisterARegisterOrOnFailure(): void {
		$failing = new class {
			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				throw new RuntimeException('db down');
			}
		};
		$unused = self::service(static fn (): array => [['portal' => 'gem', 'date' => '2026-11-01']]);

		$this->assertSame([], $this->store(null)->dailyBetween('gem', 'a', 'b'));
		$this->assertSame([], $this->store($unused, false)->outagesBetween('gem', 'a', 'b'));
		$this->assertSame([], $unused->found);
		$this->assertNull($this->store($failing)->openOutage('gem'));
		$this->assertSame(0, $this->store($failing)->purgeBefore('2026-01-01'));
		$this->assertSame(['Portaliq: availability read failed', ['schema' => 'portalAvailabilityOutage', 'reason' => 'db down']], $this->logged[0]);
	}//end testReadsAreEmptyWithoutOpenRegisterARegisterOrOnFailure()
}//end class
