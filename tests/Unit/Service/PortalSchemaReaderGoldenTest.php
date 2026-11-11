<?php

/**
 * PortalSchemaReader::readSchema(): every way the schema mapper is reached,
 * and what it answers.
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

use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The schema read, as recorded.
 */
class PortalSchemaReaderGoldenTest extends TestCase {
	/**
	 * A reader over a container that answers as given.
	 *
	 * @param mixed $service What the container answers for the schema mapper, or an exception.
	 *
	 * @return PortalSchemaReader
	 */
	private function reader(mixed $service): PortalSchemaReader {
		$container = $this->createMock(ContainerInterface::class);
		if ($service instanceof \Throwable) {
			$container->method('get')->willThrowException($service);
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Db\\SchemaMapper')->willReturn($service);
		}

		return new PortalSchemaReader($container, $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * No mapper, a mapper that is no object, an empty or failing lookup, and
	 * a found schema.
	 *
	 * @return void
	 */
	public function testEveryWayTheMapperIsReachedReadsAsRecorded(): void {
		$this->assertNull($this->reader(new RuntimeException('no openregister'))->readSchema('case'));
		$this->assertNull($this->reader('not an object')->readSchema('case'));
		$this->assertNull($this->reader(new RuntimeException('never asked'))->readSchema(''));

		$mapper = new class {
			/**
			 * The calls, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			public function findBySlug(string $slug, int $limit, int $offset, bool $_rbac, bool $_multitenancy): array {
				$this->calls[] = compact('slug', 'limit', 'offset', '_rbac', '_multitenancy');
				if ($slug === 'boom') {
					throw new RuntimeException('down');
				}

				if ($slug === 'none') {
					return [];
				}

				return [new class { public function jsonSerialize(): array { return ['slug' => 'case', 'properties' => []]; } }];
			}
		};
		$reader = $this->reader($mapper);
		$this->assertSame(['slug' => 'case', 'properties' => []], $reader->readSchema('case'));
		$this->assertNull($reader->readSchema('none'));
		$this->assertNull($reader->readSchema('boom'));
		$this->assertSame(['slug' => 'case', 'limit' => 1, 'offset' => 0, '_rbac' => false, '_multitenancy' => false], $mapper->calls[0]);
	}//end testEveryWayTheMapperIsReachedReadsAsRecorded()
}//end class
