<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\BackfillNewsPublishedAt;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * news-publish-date T3: a news item published before `publishedAt` existed
 * takes its creation moment, so the feed keeps its order. A draft, an item
 * that already carries the moment, and an undated item stay as they are, and
 * a second run writes nothing.
 *
 * @spec openspec/changes/news-publish-date/specs/portaliq-cms/spec.md#requirement-a-news-item-carries-the-moment-it-was-published
 */
class BackfillNewsPublishedAtTest extends TestCase {
	/**
	 * A store of news items that pages like OpenRegister and records each save.
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

	private function step(object $store, bool $schemaExists = true): BackfillNewsPublishedAt {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn($schemaExists);

		return new BackfillNewsPublishedAt($container, $context, $this->createMock(LoggerInterface::class));
	}

	public function testAPublishedItemWithoutTheMomentTakesItsCreationMoment(): void {
		$store = $this->store(
			[
				['title' => 'Old', 'status' => 'published', '@self' => ['uuid' => 'n1', 'created' => '2026-09-01T10:00:00+02:00']],
				['title' => 'Draft', 'status' => 'draft', '@self' => ['uuid' => 'n2', 'created' => '2026-09-02T08:00:00+00:00']],
				['title' => 'Stamped', 'status' => 'published', 'publishedAt' => '2026-10-01T08:00:00+00:00', '@self' => ['uuid' => 'n3', 'created' => '2026-09-03T08:00:00+00:00']],
				['title' => 'Undated', 'status' => 'published', '@self' => ['uuid' => 'n4']],
			]
		);

		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertCount(1, $store->saves);
		$this->assertSame('n1', $store->saves[0]['uuid']);
		$this->assertSame('2026-09-01T08:00:00+00:00', $store->saves[0]['object']['publishedAt']);
		$this->assertSame('Old', $store->saves[0]['object']['title']);
		$this->assertArrayNotHasKey('@self', $store->saves[0]['object']);

		$this->step($store)->run($this->createMock(IOutput::class));
		$this->assertCount(1, $store->saves, 'a second run writes nothing');
	}

	public function testItReadsEveryPage(): void {
		$rows = [];
		for ($i = 0; $i < 150; $i++) {
			$rows[] = ['title' => 'N' . $i, 'status' => 'published', '@self' => ['uuid' => 'n' . $i, 'created' => '2026-09-01T08:00:00+00:00']];
		}

		$store = $this->store($rows);
		$this->step($store)->run($this->createMock(IOutput::class));

		$this->assertCount(150, $store->saves);
	}

	public function testWithoutTheSchemaItDoesNothing(): void {
		$store = $this->store([['title' => 'Old', 'status' => 'published', '@self' => ['uuid' => 'n1', 'created' => '2026-09-01T08:00:00+00:00']]]);

		$this->step($store, false)->run($this->createMock(IOutput::class));

		$this->assertSame([], $store->saves);
	}
}
