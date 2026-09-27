<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\ActivityStore;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The activity services' one door to OpenRegister
 * (extracurricular-activity-offer). The load-bearing property: a read that
 * fails is null, never an empty list.
 *
 * @spec openspec/changes/extracurricular-activity-offer/design.md#d6-a-store-class-instead-of-openregister-calls-in-every-service
 */
class ActivityStoreTest extends TestCase {
	/**
	 * OpenRegister's object service id.
	 */
	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * A fake ObjectService that records calls.
	 *
	 * @param array<int, mixed> $rows What findAll returns.
	 * @param bool $throw Whether findAll and saveObject throw.
	 *
	 * @return object
	 */
	private function objectService(array $rows = [], bool $throw = false): object {
		return new class($rows, $throw) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * @var string
			 */
			public string $schema = '';

			/**
			 * @param array<int, mixed> $rows
			 * @param bool $throw
			 */
			public function __construct(private array $rows, private bool $throw) {
			}

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->throw === true) {
					throw new RuntimeException('database gone');
				}

				return $this->rows;
			}

			/**
			 * @param array<string, mixed> $object
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->throw === true) {
					throw new RuntimeException('database gone');
				}

				$this->saves[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
				return $object + ['id' => ($uuid ?? 'new-id')];
			}
		};
	}//end objectService()

	/**
	 * The store over a given ObjectService (or none).
	 *
	 * @param object|null $objectService The fake, or null for "OpenRegister absent".
	 *
	 * @return ActivityStore
	 */
	private function store(?object $objectService): ActivityStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		}

		if ($objectService !== null) {
			$container->method('get')->with(self::OS)->willReturn($objectService);
		}

		return new ActivityStore($container, $this->createMock(LoggerInterface::class));
	}//end store()

	/**
	 * Rows come back normalised; a failed or impossible read is null.
	 *
	 * @return void
	 */
	public function testAFailedReadIsNullNotEmpty(): void {
		$entity = new class {
			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'b', 'title' => 'Schaakclub'];
			}
		};
		$fake = $this->objectService(rows: [['id' => 'a'], $entity, 'junk']);

		$this->assertSame([['id' => 'a'], ['id' => 'b', 'title' => 'Schaakclub']], $this->store($fake)->rows(ActivityStore::OFFER));
		$this->assertSame('activityOffer', $fake->schema);
		$this->assertSame([], $this->store($this->objectService(rows: []))->rows(ActivityStore::OFFER));
		$this->assertNull($this->store($this->objectService(throw: true))->rows(ActivityStore::OFFER));
		$this->assertNull($this->store(null)->rows(ActivityStore::OFFER));
	}//end testAFailedReadIsNullNotEmpty()

	/**
	 * A row answers to its id, uuid and slug; lookup() uses all three.
	 *
	 * @return void
	 */
	public function testARowAnswersToItsIdAndSlug(): void {
		$row = ['id' => 'uuid-1', '@self' => ['id' => 'uuid-1', 'slug' => 'activity-schaakclub-najaar']];
		$store = $this->store($this->objectService(rows: [$row, ['id' => 'uuid-2']]));

		$this->assertSame(['uuid-1', 'activity-schaakclub-najaar'], $store->keys($row));
		$this->assertSame('uuid-1', $store->idOf($row));
		$this->assertSame('uuid-9', $store->idOf(['@self' => ['uuid' => 'uuid-9']]));
		$this->assertSame('', $store->idOf([]));
		$this->assertSame($row, $store->lookup(ActivityStore::OFFER, 'activity-schaakclub-najaar'));
		$this->assertSame($row, $store->lookup(ActivityStore::OFFER, 'uuid-1'));
		$this->assertNull($store->lookup(ActivityStore::OFFER, 'unknown'));
		$this->assertNull($store->lookup(ActivityStore::OFFER, ''));
	}//end testARowAnswersToItsIdAndSlug()

	/**
	 * A save creates without an id and updates with one, RBAC off, envelope
	 * dropped; a failed save is null.
	 *
	 * @return void
	 */
	public function testSaveCreatesOrUpdates(): void {
		$fake = $this->objectService();
		$store = $this->store($fake);

		$created = $store->save(ActivityStore::SIGNUP, ['childRef' => 'child-devries-lars', '@self' => ['slug' => 'x']]);
		$updated = $store->save(ActivityStore::SIGNUP, ['status' => 'withdrawn'], 'signup-1');

		$this->assertSame('new-id', $created['id']);
		$this->assertSame('signup-1', $updated['id']);
		$this->assertNull($fake->saves[0]['uuid']);
		$this->assertArrayNotHasKey('@self', $fake->saves[0]['object']);
		$this->assertSame('signup-1', $fake->saves[1]['uuid']);
		$this->assertFalse($fake->saves[0]['rbac']);
		$this->assertNull($this->store($this->objectService(throw: true))->save(ActivityStore::SIGNUP, ['a' => 1]));
		$this->assertNull($this->store(null)->save(ActivityStore::SIGNUP, ['a' => 1]));
	}//end testSaveCreatesOrUpdates()
}//end class
