<?php

/**
 * RepairPagingTest
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Repair
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

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use OCA\Portaliq\Repair\BackfillNewsPublishedAt;
use OCA\Portaliq\Repair\MoveActionSummarySentence;
use OCA\Portaliq\Repair\MoveBindingDestination;
use OCA\Portaliq\Service\PortalRegisterContext;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A repair step pages by the rows OpenRegister returned, so a row that is
 * not an array neither ends the run early nor shifts the next offset.
 *
 * @covers \OCA\Portaliq\Repair\BackfillNewsPublishedAt
 * @covers \OCA\Portaliq\Repair\MoveActionSummarySentence
 * @covers \OCA\Portaliq\Repair\MoveBindingDestination
 * @covers \OCA\Portaliq\Repair\PagedSchemaRead
 */
class RepairPagingTest extends TestCase {
	/**
	 * The repair steps that page through their schema.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function steps(): array {
		return [
			'binding destination' => [MoveBindingDestination::class],
			'action summary' => [MoveActionSummarySentence::class],
			'news published at' => [BackfillNewsPublishedAt::class],
		];
	}//end steps()

	/**
	 * A full first page holding one junk row is followed by the next page at
	 * offset 100, and every array row on both pages is handed to the step.
	 *
	 * @param class-string $class The repair step.
	 *
	 * @return void
	 */
	#[DataProvider('steps')]
	public function testAJunkRowOnAFullPageStillReadsTheNextPage(string $class): void {
		$rows = ['junk'];
		for ($i = 1; $i < 103; $i++) {
			$rows[] = ['id' => 'r-' . $i, '@self' => ['uuid' => 'r-' . $i]];
		}

		$store = new class ($rows) {
			public array $offsets = [];

			public function __construct(private array $rows) {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->offsets[] = (int)($config['offset'] ?? 0);

				return array_slice($this->rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 100));
			}

			public function saveObject(array $object, ?ObjectSaveOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$register = ($options?->target->register ?? null);
				$schema = ($options?->target->schema ?? null);
				$uuid = ($options?->uuid ?? null);
				return $object;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$context = $this->createMock(PortalRegisterContext::class);
		$context->method('apply')->willReturn(true);

		(new $class($container, $context, $this->createMock(LoggerInterface::class)))->run($this->createMock(IOutput::class));

		$this->assertSame([0, 100], $store->offsets);
	}//end testAJunkRowOnAFullPageStillReadsTheNextPage()
}//end class
