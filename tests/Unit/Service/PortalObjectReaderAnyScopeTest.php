<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalFieldProjector;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A read over several scope fields: one scoped read per field, each row
 * verified on the field it was read by, merged once by id.
 *
 * @spec openspec/changes/personal-action-list/tasks.md#t02
 */
class PortalObjectReaderAnyScopeTest extends TestCase {

	private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

	public function testReadsEveryFieldOnceEachAndDropsRowsOutsideTheField(): void {
		$objectService = new class {

			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $filters = [];

			public function setRegister(string $register): self {
				return $this;
			}

			public function setSchema(string $schema): self {
				return $this;
			}

			/**
			 * @param array<string, mixed> $config
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->filters[] = $config['filters'];
				$rows = [
					['id' => 'a1', 'owner' => 's1', 'assignee' => 's1', 'title' => 'Own'],
					['id' => 'a2', 'owner' => 's2', 'assignee' => 's1', 'title' => 'Given to me'],
					['id' => 'a3', 'owner' => 's2', 'assignee' => 's2', 'title' => 'Not mine'],
				];

				return $rows;
			}
		};

		$reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class), new PortalFieldProjector($this->createMock(LoggerInterface::class)));
		$rows   = $reader->readCollectionByAnyScope('portaliq', 'portalAction', ['owner', 'assignee', 'owner'], 's1');

		$this->assertSame(['a1', 'a2'], array_column($rows, 'id'));
		$this->assertSame([['owner' => 's1'], ['assignee' => 's1']], $objectService->filters);
	}

	public function testAnEmptySubjectReadsNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class), new PortalFieldProjector($this->createMock(LoggerInterface::class)));

		$this->assertSame([], $reader->readCollectionByAnyScope('portaliq', 'portalAction', ['owner', 'assignee'], ''));
	}

	private function container(object $objectService): ContainerInterface {
		$mock = $this->createMock(ContainerInterface::class);
		$mock->method('get')->willReturnCallback(
			function (string $id) use ($objectService) {
				if ($id === self::OS) {
					return $objectService;
				}

				throw new RuntimeException('no service: '.$id);
			}
		);

		return $mock;
	}
}
