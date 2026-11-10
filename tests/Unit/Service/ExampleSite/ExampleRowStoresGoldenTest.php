<?php

/**
 * ExampleRowStoresGoldenTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\ExampleSite;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use JsonSerializable;
use OCA\Portaliq\Service\ExampleResident\ExampleResidentStore;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalRegisterContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;

/**
 * What the example site and example resident stores answer, per store world:
 * whether OpenRegister is there, the ids they read off rows, the rows a read
 * keeps, and the rows a read by id keeps.
 *
 * @covers \OCA\Portaliq\Service\ExampleSite\ExampleSiteStore
 * @covers \OCA\Portaliq\Service\ExampleResident\ExampleResidentStore
 * @uses   \OCA\Portaliq\Service\ObjectServiceLookup
 * @uses   \OCA\Portaliq\Service\ObjectRowNormaliser
 * @uses   \OCA\Portaliq\Service\RowIdOf
 */
class ExampleRowStoresGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../../Fixtures/Golden/example-row-stores.json';

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Every store answer matches the recorded one.
	 *
	 * @return void
	 */
	public function testEveryStoreAnswerMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $worldName => $rows) {
			$container = $this->container(rows: $rows);
			$site      = new ExampleSiteStore(
				container: $container,
				context: $this->context(),
				writer: $this->createMock(originalClassName: PortalObjectWriter::class),
				logger: new NullLogger()
			);
			$resident  = new ExampleResidentStore(container: $container, logger: new NullLogger());

			$actual[$worldName] = [
				'site available' => $site->available(),
				'resident available' => $resident->available(),
				'site find' => $site->find(schema: 'page', filters: ['portal' => 'zuid']),
				'resident find' => $resident->find(register: 'portaliq', schema: 'page', filters: []),
				'resident get a' => $resident->get(register: 'portaliq', schema: 'page', id: 'a'),
				'resident get b' => $resident->get(register: 'portaliq', schema: 'page', id: 'b'),
			];
		}

		foreach ($this->idRows() as $rowName => $row) {
			$site     = new ExampleSiteStore(
				container: $this->container(rows: null),
				context: $this->context(),
				writer: $this->createMock(originalClassName: PortalObjectWriter::class),
				logger: new NullLogger()
			);
			$resident = new ExampleResidentStore(container: $this->container(rows: null), logger: new NullLogger());
			$actual['idOf | ' . $rowName] = ['site' => $site->idOf(row: $row), 'resident' => $resident->idOf(row: $row)];
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $case => $answer) {
			$this->assertSame(expected: $answer, actual: json_decode((string)json_encode($actual[$case] ?? null), true), message: $case);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testEveryStoreAnswerMatchesTheGolden()

	/**
	 * The rows a store world holds, by name; null is no OpenRegister, a
	 * Throwable is a read that fails.
	 *
	 * @return array<string, mixed>
	 */
	private function worlds(): array {
		$entity = new class implements JsonSerializable {
			/**
			 * An entity that serialises to a row.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return ['id' => 'b', 'route' => '/afval', '@self' => ['id' => 'b']];
			}
		};
		$scalarEntity = new class implements JsonSerializable {
			/**
			 * An entity that serialises to text.
			 *
			 * @return mixed
			 */
			public function jsonSerialize(): mixed {
				return 'not a row';
			}
		};

		return [
			'no object service' => null,
			'read fails' => new RuntimeException('down'),
			'no rows' => [],
			'arrays' => [['id' => 'a', 'route' => '/', '@self' => ['id' => 'a']], ['uuid' => 'b', 'route' => '/b']],
			'mixed' => [['@self' => ['uuid' => 'a'], 'route' => '/'], $entity, $scalarEntity, 'text', 7, null, new stdClass()],
			'id mismatch' => [['id' => 'z', '@self' => ['id' => 'z']]],
		];
	}//end worlds()

	/**
	 * Rows to read an id off, by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function idRows(): array {
		return [
			'empty' => [],
			'self id' => ['@self' => ['id' => 's1', 'uuid' => 's2'], 'id' => 'r1', 'uuid' => 'r2'],
			'self uuid' => ['@self' => ['uuid' => 's2'], 'id' => 'r1'],
			'row id' => ['id' => 'r1', 'uuid' => 'r2'],
			'row uuid' => ['uuid' => 'r2'],
			'empty self id' => ['@self' => ['id' => ''], 'id' => 'r1'],
			'int ids' => ['@self' => ['id' => 5], 'id' => 6, 'uuid' => 'r2'],
			'self not an array' => ['@self' => 'x', 'id' => 'r1'],
			'only ints' => ['id' => 6],
		];
	}//end idRows()

	/**
	 * A context that always points the service at the schema.
	 *
	 * @return PortalRegisterContext
	 */
	private function context(): PortalRegisterContext {
		$context = $this->createMock(originalClassName: PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		return $context;
	}//end context()

	/**
	 * A container whose object service holds `$rows`.
	 *
	 * @param mixed $rows The rows, null for no service, a Throwable for a failing read.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $rows): ContainerInterface {
		$service = null;
		if ($rows !== null) {
			$service = new class($rows) {
				/**
				 * @param mixed $rows The rows, or a Throwable to throw.
				 */
				public function __construct(private mixed $rows) {
				}

				/**
				 * @param string $register The register.
				 *
				 * @return void
				 */
				public function setRegister(string $register): void {
				}

				/**
				 * @param string $schema The schema.
				 *
				 * @return void
				 */
				public function setSchema(string $schema): void {
				}

				/**
				 * @param array<string, mixed> $config        The read config.
				 * @param bool                 $_rbac         Access rules.
				 * @param bool                 $_multitenancy Tenancy rules.
				 *
				 * @return mixed
				 */
				public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): mixed {
					if ($this->rows instanceof \Throwable) {
						throw $this->rows;
					}

					return $this->rows;
				}

				/**
				 * @param string $id            The id.
				 * @param mixed  $register      The register.
				 * @param mixed  $schema        The schema.
				 * @param bool   $_rbac         Access rules.
				 * @param bool   $_multitenancy Tenancy rules.
				 *
				 * @return mixed
				 */
				public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
					$register = ($options?->register ?? null);
					$schema   = ($options?->schema ?? null);
					if ($this->rows instanceof \Throwable) {
						throw $this->rows;
					}

					foreach ($this->rows as $row) {
						if (is_array($row) === true && ($row['id'] ?? $row['uuid'] ?? $row['@self']['uuid'] ?? null) === $id) {
							return $row;
						}
					}

					return ($this->rows[1] ?? null);
				}
			};
		}

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === self::OS && $service !== null) {
					return $service;
				}

				throw new RuntimeException('no ' . $id);
			}
		);

		return $container;
	}//end container()
}//end class
