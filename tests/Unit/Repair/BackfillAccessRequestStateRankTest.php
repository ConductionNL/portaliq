<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\BackfillAccessRequestStateRank;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * access-requests-open-first: an access request stored before `stateRank`
 * existed takes the rank of its state, a stale rank is corrected, a right
 * one and a request with an unknown state stay as they are, and a second run
 * writes nothing.
 *
 * @spec openspec/changes/access-requests-open-first/specs/portal-identity/spec.md#requirement-open-access-requests-come-first
 */
class BackfillAccessRequestStateRankTest extends TestCase {
	/**
	 * A store of access requests that pages like OpenRegister and records each save.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return object
	 */
	private function store(array $rows): object {
		return new class($rows) {
			/**
			 * @var array<int, array{uuid: string|null, object: array<string, mixed>}>
			 */
			public array $saves = [];

			/**
			 * @param array<int, array<string, mixed>> $rows The stored rows.
			 */
			public function __construct(public array $rows) {
			}

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			/**
			 * @param array<string, mixed> $object
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->saves[] = ['uuid' => $uuid, 'object' => $object];
				foreach ($this->rows as $index => $row) {
					if (($row['@self']['uuid'] ?? null) === $uuid) {
						$this->rows[$index] = $object + ['@self' => $row['@self']];
					}
				}

				return $object;
			}
		};
	}

	private function step(object $store, bool $schemaExists = true): BackfillAccessRequestStateRank {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn($schemaExists);

		return new BackfillAccessRequestStateRank($container, $context, $this->createMock(LoggerInterface::class));
	}

	public function testEveryRequestTakesTheRankOfItsState(): void {
		$store = $this->store([
			['state' => 'pending', '@self' => ['uuid' => 'a']],
			['state' => 'granted', '@self' => ['uuid' => 'b']],
			['state' => 'refused', 'stateRank' => 0, '@self' => ['uuid' => 'c']],
			['state' => 'granted', 'stateRank' => 1, '@self' => ['uuid' => 'd']],
			['state' => 'withdrawn', '@self' => ['uuid' => 'e']],
		]);

		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertSame(['a' => 0, 'b' => 1, 'c' => 2], array_column(array_map(static fn (array $save): array => ['uuid' => $save['uuid'], 'rank' => $save['object']['stateRank']], $store->saves), 'rank', 'uuid'));
		$this->assertArrayNotHasKey('@self', $store->saves[0]['object']);

		$store->saves = [];
		$this->step($store)->run($this->createMock(IOutput::class));
		$this->assertSame([], $store->saves, 'a second run writes nothing');
	}//end testEveryRequestTakesTheRankOfItsState()

	public function testWithoutTheSchemaNothingIsWritten(): void {
		$store = $this->store([['state' => 'pending', '@self' => ['uuid' => 'a']]]);

		$this->step($store, schemaExists: false)->run($this->createMock(IOutput::class));

		$this->assertSame([], $store->saves);
	}//end testWithoutTheSchemaNothingIsWritten()
}//end class
