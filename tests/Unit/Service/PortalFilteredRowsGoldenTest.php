<?php

/**
 * PortalFilteredRows::outerRows(): the queries a via read asks, in order,
 * and the rows it keeps.
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

use OCA\Portaliq\Service\PortalFilteredRows;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The outer query of a via read, as recorded.
 */
class PortalFilteredRowsGoldenTest extends TestCase {
	/**
	 * An object service that answers pages in turn and records each call.
	 *
	 * @param array<int, array<int, mixed>> $pages The pages, one per call.
	 * @param bool                          $fails Whether the first call throws.
	 *
	 * @return object
	 */
	private static function objects(array $pages, bool $fails = false): object {
		return new class($pages, $fails) {
			/**
			 * The calls, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param array<int, array<int, mixed>> $pages The pages.
			 * @param bool                          $fails Whether to throw.
			 */
			public function __construct(private array $pages, private bool $fails) {
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['config' => $config, 'rbac' => $_rbac, 'mt' => $_multitenancy];
				if ($this->fails === true) {
					throw new RuntimeException('down');
				}

				return (array)array_shift($this->pages);
			}
		};
	}//end objects()

	/**
	 * A forward read asks once with the declared filter; a reverse read asks
	 * once per target with the scope field set last, stops at the limit and
	 * skips a row read twice.
	 *
	 * @return void
	 */
	public function testTheQueriesAndRowsAreAsRecorded(): void {
		$rows = new PortalFilteredRows($this->createMock(LoggerInterface::class));

		$forward = self::objects(pages: [[['id' => 'a'], ['id' => 'b']]]);
		$this->assertSame(
			[['id' => 'a'], ['id' => 'b']],
			$rows->outerRows($forward, 'lesson', 'student', ['s1' => true, 's2' => true], 'id', 5, ['status' => 'open', '' => 'x', 3 => 'y', 'student' => 'z'])
		);
		$this->assertSame(
			[['config' => ['filters' => ['status' => 'open', 'student' => 'z'], 'limit' => 5, 'offset' => 0], 'rbac' => false, 'mt' => false]],
			$forward->calls
		);

		$reverse = self::objects(pages: [[['id' => 'a'], ['uuid' => 'b']], [['id' => 'a'], ['id' => 'c']], [['id' => 'd']]]);
		$this->assertSame(
			[['id' => 'a'], ['uuid' => 'b'], ['id' => 'c']],
			$rows->outerRows($reverse, 'lesson', 'student', ['s1' => true, 's2' => true, 's3' => true], 'scopeField', 3, ['student' => 'wide', 'status' => 'open'])
		);
		$this->assertSame(
			[
				['filters' => ['student' => 's1', 'status' => 'open'], 'limit' => 3, 'offset' => 0],
				['filters' => ['student' => 's2', 'status' => 'open'], 'limit' => 1, 'offset' => 0],
			],
			array_column($reverse->calls, 'config')
		);

		$noScope = self::objects(pages: [[]]);
		$this->assertSame([], $rows->outerRows($noScope, 'lesson', '', ['s1' => true], 'scopeField', 3, []));
		$this->assertSame([['filters' => [], 'limit' => 3, 'offset' => 0]], array_column($noScope->calls, 'config'));

		$noTargets = self::objects(pages: []);
		$this->assertSame([], $rows->outerRows($noTargets, 'lesson', 'student', [], 'scopeField', 3, []));
		$this->assertSame([], $noTargets->calls);

		$this->assertSame([], $rows->outerRows(self::objects(pages: []), 'lesson', '', [], 'id', 0, []));
	}//end testTheQueriesAndRowsAreAsRecorded()

	/**
	 * A failed read is logged with its schema and answers null.
	 *
	 * @return void
	 */
	public function testAFailedReadIsLoggedAndNull(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('Portaliq: OR read failed', ['schema' => 'lesson', 'reason' => 'down']);

		$this->assertNull((new PortalFilteredRows($logger))->outerRows(self::objects(pages: [], fails: true), 'lesson', '', [], 'id', 3, []));
	}//end testAFailedReadIsLoggedAndNull()
}//end class
