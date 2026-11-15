<?php

/**
 * Golden test for RenameDutchColumns::run.
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

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\RenameDutchColumns;
use OCP\DB\Exception as DbException;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every database call, log line and output line of the repair step, per
 * world, against a recording that was made on the code before the split.
 *
 * A world names the register ids (or `throw`), the table names
 * information_schema lists (or `throw`), the columns per table (or `throw`)
 * and the statements that fail.
 *
 * @covers \OCA\Portaliq\Repair\RenameDutchColumns
 */
class RenameDutchColumnsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../Fixtures/Golden/rename-dutch-columns.json';

	/**
	 * @return void
	 */
	public function testEveryWorldMatchesTheGolden(): void {
		$actual = [];
		foreach ($this->worlds() as $name => $world) {
			$actual[$name] = $this->trace(world: $world);
		}

		if (getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
		foreach ($expected as $name => $case) {
			$this->assertSame(expected: $case, actual: $actual[$name], message: $name);
		}
	}//end testEveryWorldMatchesTheGolden()

	/**
	 * Run the step in one world and return what it did, in order.
	 *
	 * @param array<string, mixed> $world The world.
	 *
	 * @return array<int, array<int, mixed>> The log.
	 */
	private function trace(array $world): array {
		$log = [];

		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params = []) use (&$log, $world): IResult {
				$log[] = ['query', $sql, $params];
				if (($world['registers'] ?? []) === 'throw') {
					throw new DbException('registers unreadable');
				}

				$result = $this->createMock(originalClassName: IResult::class);
				$result->method('fetchAll')->willReturnCallback(
					static function (int $mode) use (&$log, $world): array {
						$log[] = ['fetchAll', $mode];
						return $world['registers'] ?? [];
					}
				);
				return $result;
			}
		);
		$db->method('prepare')->willReturnCallback(
			function (string $sql) use (&$log, $world): IPreparedStatement {
				return $this->statement(sql: $sql, world: $world, log: $log);
			}
		);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$log, $world): int {
				$log[] = ['statement', $sql];
				foreach (($world['failing'] ?? []) as $needle) {
					if (str_contains($sql, $needle) === true) {
						throw new DbException('statement refused');
					}
				}

				return 1;
			}
		);
		$db->method('getDatabasePlatform')->willReturn(
			new class {
				/**
				 * Quote like MySQL does.
				 *
				 * @param string $identifier The name.
				 *
				 * @return string The quoted name.
				 */
				public function quoteSingleIdentifier(string $identifier): string {
					return '`' . $identifier . '`';
				}//end quoteSingleIdentifier()
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$log): void {
				$log[] = ['log.warning', $message, $context];
			}
		);

		$output = $this->createMock(originalClassName: IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$log): void {
				$log[] = ['info', $message];
			}
		);

		(new RenameDutchColumns(db: $db, logger: $logger))->run(output: $output);

		return $log;
	}//end trace()

	/**
	 * A prepared statement over the world's tables or columns.
	 *
	 * @param string               $sql   The statement text.
	 * @param array<string, mixed> $world The world.
	 * @param array<int, mixed>    $log   The log, appended to.
	 *
	 * @return IPreparedStatement The statement.
	 */
	private function statement(string $sql, array $world, array &$log): IPreparedStatement {
		$log[]  = ['prepare', $sql];
		$bound  = [];
		$rows   = [];
		$tables = str_contains($sql, 'information_schema.tables');

		$stmt = $this->createMock(originalClassName: IPreparedStatement::class);
		$stmt->method('bindValue')->willReturnCallback(
			static function ($param, $value) use (&$log, &$bound): bool {
				$log[]         = ['bind', $param, $value];
				$bound[$param] = $value;
				return true;
			}
		);
		$stmt->method('execute')->willReturnCallback(
			function () use (&$log, &$bound, &$rows, $world, $tables): IResult {
				$log[] = ['execute'];
				if ($tables === true) {
					$names = ($world['tables'] ?? []);
					if ($names === 'throw') {
						throw new RuntimeException('tables unreadable');
					}

					$rows = array_map(static fn ($name): array => ['table_name' => $name], $names);
					return $this->createMock(originalClassName: IResult::class);
				}

				$columns = ($world['columns'][$bound['table']] ?? []);
				if ($columns === 'throw') {
					throw new DbException('columns unreadable');
				}

				$rows = array_map(static fn ($name): array => ['column_name' => $name], $columns);
				return $this->createMock(originalClassName: IResult::class);
			}
		);
		$stmt->method('fetch')->willReturnCallback(
			static function () use (&$rows) {
				$row = array_shift($rows);
				return $row ?? false;
			}
		);

		return $stmt;
	}//end statement()

	/**
	 * The worlds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function worlds(): array {
		$table  = 'oc_openregister_table_3_7';
		$second = 'oc_openregister_table_3_12';

		return [
			'no registers'                 => [],
			'registers unreadable'         => ['registers' => 'throw'],
			'tables unreadable'            => ['registers' => ['3'], 'tables' => 'throw'],
			'no tables'                    => ['registers' => ['3']],
			'tables of other registers'    => [
				'registers' => ['3'],
				'tables' => ['oc_openregister_table_33_1', 'oc_openregister_table_4_3', 'oc_openregister_table_3_x', 'oc_openregister_table_3_'],
			],
			'empty table name skipped'     => ['registers' => ['3'], 'tables' => ['', $table], 'columns' => [$table => ['aard']]],
			'only old columns renamed'     => ['registers' => ['3'], 'tables' => [$table], 'columns' => [$table => ['id', 'aard', 'termijn']]],
			'new columns present'          => [
				'registers' => ['3'],
				'tables' => [$table],
				'columns' => [$table => ['aard', 'nature', 'termijn', 'term']],
			],
			'mixed rename and back-fill'   => ['registers' => ['3'], 'tables' => [$table], 'columns' => [$table => ['aard', 'term', 'termijn']]],
			'nothing to move'              => ['registers' => ['3'], 'tables' => [$table], 'columns' => [$table => ['id', 'nature', 'term']]],
			'empty column name skipped'    => ['registers' => ['3'], 'tables' => [$table], 'columns' => [$table => ['', 'aard']]],
			'columns unreadable'           => [
				'registers' => ['3'],
				'tables' => [$table, $second],
				'columns' => [$table => 'throw', $second => ['termijn']],
			],
			'rename fails'                 => [
				'registers' => ['3'],
				'tables' => [$table],
				'columns' => [$table => ['aard', 'termijn']],
				'failing' => ['RENAME COLUMN `aard`'],
			],
			'back-fill fails'              => [
				'registers' => ['3'],
				'tables' => [$table],
				'columns' => [$table => ['aard', 'nature', 'termijn']],
				'failing' => ['SET `nature`'],
			],
			'every statement fails'        => [
				'registers' => ['3'],
				'tables' => [$table, $second],
				'columns' => [$table => ['aard', 'nature'], $second => ['termijn']],
				'failing' => ['oc_openregister'],
			],
			'two registers two tables'     => [
				'registers' => ['3', 5],
				'tables' => ['x_oc_openregister_table_5_2', $table, 'oc_openregister_table_5_2'],
				'columns' => [$table => ['aard'], 'x_oc_openregister_table_5_2' => ['termijn', 'term'], 'oc_openregister_table_5_2' => []],
			],
			'duplicate table listed'       => ['registers' => ['3', '3'], 'tables' => [$table, $table], 'columns' => [$table => ['aard']]],
			'register id cast to int'      => ['registers' => ['3abc'], 'tables' => [$table], 'columns' => [$table => ['termijn']]],
			'prefixless table'             => [
				'registers' => ['3'],
				'tables' => ['openregister_table_3_9'],
				'columns' => ['openregister_table_3_9' => ['aard', 'termijn', 'nature']],
			],
		];
	}//end worlds()
}//end class
