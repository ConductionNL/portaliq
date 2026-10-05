<?php

/**
 * Unit tests for PagedObjectReads.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PagedObjectReads;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Every matching row is read, page by page, in a stable order.
 */
class PagedObjectReadsTest extends TestCase {
	/**
	 * A reader that exposes the trait's read.
	 *
	 * @return object
	 */
	private function reader(): object {
		return new class {
			use PagedObjectReads;

			/**
			 * @param array<string, mixed> $filters
			 *
			 * @return array<int, mixed>|null
			 */
			public function read(object $objectService, array $filters = []): ?array {
				return $this->readEveryPage(objectService: $objectService, register: 'portaliq', schema: 'newsItem', filters: $filters);
			}
		};
	}//end reader()

	/**
	 * A store holding this many rows, paging them as OpenRegister does.
	 *
	 * @param int $total The number of rows.
	 *
	 * @return object
	 */
	private function store(int $total): object {
		return new class($total) {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			public function __construct(private int $total) {
			}

			public function setRegister(string $register): self {
				$this->calls[] = ['register' => $register];
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->calls[] = ['schema' => $schema];
				return $this;
			}

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['config' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$rows = [];
				$end = min($this->total, ($config['offset'] + $config['limit']));
				for ($i = $config['offset']; $i < $end; $i++) {
					$rows[] = ['id' => 'row-' . $i];
				}

				return $rows;
			}
		};
	}//end store()

	/**
	 * Past the first 500 rows nothing is dropped: the read pages on until a
	 * short page, with the filters and a stable order on every page, and the
	 * register and schema set again before each one.
	 */
	public function testEveryPageIsReadWithTheFiltersInAStableOrder(): void {
		$store = $this->store(1003);
		$rows = $this->reader()->read($store, ['status' => 'published']);

		$this->assertCount(1003, $rows);
		$this->assertSame('row-1002', $rows[1002]['id']);

		$configs = array_values(array_filter($store->calls, static fn (array $call): bool => isset($call['config'])));
		$this->assertSame([0, 500, 1000], array_map(static fn (array $call): int => $call['config']['offset'], $configs));
		foreach ($configs as $call) {
			$this->assertSame(['status' => 'published'], $call['config']['filters']);
			$this->assertSame(500, $call['config']['limit']);
			$this->assertSame(['_created' => 'ASC', '_uuid' => 'ASC'], $call['config']['sort']);
			$this->assertFalse($call['rbac']);
			$this->assertFalse($call['multitenancy']);
		}

		$this->assertSame(['register' => 'portaliq'], $store->calls[0]);
		$this->assertSame(['schema' => 'newsItem'], $store->calls[1]);
		$this->assertSame(['register' => 'portaliq'], $store->calls[3]);
	}//end testEveryPageIsReadWithTheFiltersInAStableOrder()

	/**
	 * A full last page costs one more, empty, read; an empty store one read.
	 */
	public function testAnExactMultipleOfThePageEndsOnAnEmptyPage(): void {
		$this->assertCount(1000, $this->reader()->read($this->store(1000)));
		$this->assertSame([], $this->reader()->read($this->store(0)));
	}//end testAnExactMultipleOfThePageEndsOnAnEmptyPage()

	/**
	 * A page that is not a list makes the whole read null.
	 */
	public function testAPageThatIsNotAListYieldsNull(): void {
		$store = new class {
			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return 'not a list';
			}
		};

		$this->assertNull($this->reader()->read($store));
	}//end testAPageThatIsNotAListYieldsNull()

	/**
	 * A store that ignores the offset and keeps answering full pages stops at
	 * the hard limit with an error, never a silently cut-off answer.
	 */
	public function testAStoreThatNeverEndsHitsTheHardStop(): void {
		$store = new class {
			public int $reads = 0;

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @return array<int, array<string, string>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads++;
				return array_fill(0, 500, ['id' => 'same']);
			}
		};

		try {
			$this->reader()->read($store);
			$this->fail('the read should stop');
		} catch (RuntimeException $e) {
			$this->assertStringContainsString('newsItem', $e->getMessage());
		}

		$this->assertSame(200, $store->reads);
	}//end testAStoreThatNeverEndsHitsTheHardStop()
}//end class
