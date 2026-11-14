<?php

/**
 * NormalisedRowReads, ObjectFileLookup and PublishedPortalWalk: three
 * service traits, exercised through small host classes.
 *
 * @category Tests
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

use OCA\Portaliq\Service\NormalisedRowReads;
use OCA\Portaliq\Service\ObjectFileLookup;
use OCA\Portaliq\Service\PublishedPortalWalk;
use PHPUnit\Framework\TestCase;

/**
 * The service traits.
 */
class ServiceTraitsTest extends TestCase {
	/**
	 * A host for NormalisedRowReads that drops rows without a title.
	 *
	 * @return object
	 */
	private function rowReader(): object {
		return new class {
			use NormalisedRowReads;

			/**
			 * Keep the rows that normalise.
			 *
			 * @param array<int, mixed> $rows The rows.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function kept(array $rows): array {
				return $this->keepNormalised(rows: $rows);
			}//end kept()

			/**
			 * The id of a row.
			 *
			 * @param array<string, mixed> $row The row.
			 *
			 * @return string
			 */
			public function idOf(array $row): string {
				return $this->rowId(row: $row);
			}//end idOf()

			/**
			 * A row with a title, or null.
			 *
			 * @param mixed $row The row.
			 *
			 * @return array<string, mixed>|null
			 */
			private function normalise(mixed $row): ?array {
				if (is_array($row) === false || isset($row['title']) === false) {
					return null;
				}

				return ['title' => (string)$row['title']];
			}//end normalise()
		};
	}//end rowReader()

	/**
	 * Rows that do not normalise are dropped, the rest re-indexed in order.
	 *
	 * @return void
	 */
	public function testRowsThatDoNotNormaliseAreDropped(): void {
		$kept = $this->rowReader()->kept(rows: [['title' => 'a'], 'junk', ['id' => 'x'], ['title' => 2]]);
		$this->assertSame(expected: [['title' => 'a'], ['title' => '2']], actual: $kept);
	}//end testRowsThatDoNotNormaliseAreDropped()

	/**
	 * A row's id is read from id, uuid, then the object metadata.
	 *
	 * @return void
	 */
	public function testARowsIdIsFoundWhereverItIs(): void {
		$reader = $this->rowReader();
		$this->assertSame(expected: 'i1', actual: $reader->idOf(row: ['id' => 'i1', 'uuid' => 'u1']));
		$this->assertSame(expected: 'u1', actual: $reader->idOf(row: ['uuid' => 'u1']));
		$this->assertSame(expected: '7', actual: $reader->idOf(row: ['id' => 7]));
		$this->assertSame(expected: 's1', actual: $reader->idOf(row: ['@self' => ['id' => 's1', 'uuid' => 's2']]));
		$this->assertSame(expected: 's2', actual: $reader->idOf(row: ['@self' => ['uuid' => 's2']]));
		$this->assertSame(expected: '', actual: $reader->idOf(row: ['@self' => 'x']));
		$this->assertSame(expected: '', actual: $reader->idOf(row: []));
	}//end testARowsIdIsFoundWhereverItIs()

	/**
	 * A file is described by its id, name and size, whichever it can tell.
	 *
	 * @return void
	 */
	public function testAFileIsDescribedByWhatItCanTell(): void {
		$host = new class {
			use ObjectFileLookup;

			/**
			 * Describe a file.
			 *
			 * @param mixed $file The file.
			 * @param string|null $name The fallback name.
			 *
			 * @return array{id: mixed, name: string|null, size: mixed}
			 */
			public function describe(mixed $file, ?string $name=null): array {
				return $this->normalise(file: $file, fileName: $name);
			}//end describe()
		};
		$file = new class {
			/**
			 * The id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return 42;
			}//end getId()

			/**
			 * The name.
			 *
			 * @return string
			 */
			public function getName(): string {
				return 'bewijs.pdf';
			}//end getName()

			/**
			 * The size.
			 *
			 * @return int
			 */
			public function getSize(): int {
				return 2048;
			}//end getSize()
		};
		$this->assertSame(expected: ['id' => 42, 'name' => 'bewijs.pdf', 'size' => 2048], actual: $host->describe(file: $file, name: 'ignored.pdf'));
		$bare = $host->describe(file: new \stdClass(), name: 'upload.pdf');
		$this->assertSame(expected: ['id' => null, 'name' => 'upload.pdf', 'size' => null], actual: $bare);
		$this->assertSame(expected: ['id' => null, 'name' => null, 'size' => null], actual: $host->describe(file: 'path/to/file'));
	}//end testAFileIsDescribedByWhatItCanTell()

	/**
	 * The walk splits the published portals into own portals and roll-ups, skipping unnamed ones.
	 *
	 * @return void
	 */
	public function testTheWalkSplitsOwnPortalsFromRollups(): void {
		$portals = new class {
			/**
			 * The published portals.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function allPublishedPortals(): array {
				return [['slug' => ' mijn '], ['slug' => 'regio'], ['slug' => ''], ['title' => 'no slug'], ['slug' => 'ondernemers']];
			}//end allPublishedPortals()
		};
		$config  = new class {
			/**
			 * A portal's traffic config.
			 *
			 * @param array<string, mixed> $portal The portal.
			 *
			 * @return array<string, mixed>
			 */
			public function resolve(array $portal): array {
				if ($portal['slug'] === 'regio') {
					return ['rollupOf' => ['mijn', 'ondernemers']];
				}

				return ['rollupOf' => [], 'retention' => 30];
			}//end resolve()
		};
		$host    = new class($portals, $config) {
			use PublishedPortalWalk;

			/**
			 * Hold the collaborators.
			 *
			 * @param object $portals The published portals.
			 * @param object $config The config resolver.
			 */
			public function __construct(private object $portals, private object $config) {
			}//end __construct()

			/**
			 * Walk the portals.
			 *
			 * @return array{0: array<int, array{0: string, 1: array<string, mixed>}>, 1: array<string, array<int, string>>}
			 */
			public function walk(): array {
				return $this->portalWalk();
			}//end walk()
		};
		[$own, $rollups] = $host->walk();
		$resolved = ['rollupOf' => [], 'retention' => 30];
		$this->assertSame(expected: [['mijn', $resolved], ['ondernemers', $resolved]], actual: $own);
		$this->assertSame(expected: ['regio' => ['mijn', 'ondernemers']], actual: $rollups);
	}//end testTheWalkSplitsOwnPortalsFromRollups()
}//end class
