<?php

/**
 * Unit tests for NewsRowSource.
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
 *
 * @spec openspec/changes/news-and-newsletter-authoring/design.md#architecture-overview
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\NewsRowSource;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The OpenRegister read behind the news feed and archive.
 */
class NewsRowSourceTest extends TestCase {
	/**
	 * A container whose ObjectService answers these rows, or throws.
	 *
	 * @param array<int, mixed>|null $rows The rows, or null to throw on read.
	 *
	 * @return ContainerInterface
	 */
	private function container(?array $rows): ContainerInterface {
		$objectService = new class($rows) {
			public string $register = '';

			public string $schema = '';

			public function __construct(private ?array $rows) {
			}

			public function setRegister(string $register): self {
				$this->register = $register;
				return $this;
			}

			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->rows === null) {
					throw new RuntimeException('schema not imported');
				}

				return $this->rows;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		return $container;
	}//end container()

	/**
	 * Arrays pass through, entities are serialised, anything else is dropped.
	 */
	public function testFindAllNormalisesRows(): void {
		$entity = new class {
			public function jsonSerialize(): array {
				return ['id' => 'n2', 'title' => 'Entity'];
			}
		};
		$source = new NewsRowSource($this->container([['id' => 'n1'], $entity, 'junk']), $this->createMock(LoggerInterface::class));

		$this->assertSame([['id' => 'n1'], ['id' => 'n2', 'title' => 'Entity']], $source->findAll('newsItem'));
	}//end testFindAllNormalisesRows()

	/**
	 * A failed read warns and yields no rows; a missing ObjectService yields no rows.
	 */
	public function testAFailedOrImpossibleReadYieldsNoRows(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$this->assertSame([], (new NewsRowSource($this->container(null), $logger))->findAll('newsletter'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		$this->assertSame([], (new NewsRowSource($container, $this->createMock(LoggerInterface::class)))->findAll('newsItem'));
	}//end testAFailedOrImpossibleReadYieldsNoRows()

	/**
	 * The plain filters reach the store, and every page is read.
	 */
	public function testFindAllPassesTheFiltersAndReadsEveryPage(): void {
		$objectService = new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $configs = [];

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->configs[] = $config;
				return array_fill(0, max(0, min(500, 501 - $config['offset'])), ['id' => 'n', 'status' => 'published']);
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$rows = (new NewsRowSource($container, $this->createMock(LoggerInterface::class)))->findAll('newsItem', ['status' => 'published']);

		$this->assertCount(501, $rows);
		$this->assertCount(2, $objectService->configs);
		$this->assertSame(['status' => 'published'], $objectService->configs[1]['filters']);
	}//end testFindAllPassesTheFiltersAndReadsEveryPage()

	/**
	 * The id is read from id, then uuid, then the envelope.
	 */
	public function testRowIdReadsIdUuidOrEnvelope(): void {
		$source = new NewsRowSource($this->container([]), $this->createMock(LoggerInterface::class));

		$this->assertSame('a', $source->rowId(['id' => 'a', 'uuid' => 'b']));
		$this->assertSame('b', $source->rowId(['uuid' => 'b']));
		$this->assertSame('c', $source->rowId(['@self' => ['id' => 'c']]));
		$this->assertSame('', $source->rowId([]));
	}//end testRowIdReadsIdUuidOrEnvelope()
}//end class
