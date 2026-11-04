<?php

/**
 * Portaliq RenameDutchColumns Repair Step
 *
 * Moves stored data from the Dutch columns to the English ones the shillinq
 * register now declares. Covers every vocabulary cluster migrated so far, not
 * only amounts — the class was renamed from RenameDutchAmountColumns when the
 * second cluster landed, because one register-scoped step must carry them all.
 *
 * WHY THIS IS NEEDED. OpenRegister does not store an object as a JSON blob
 * keyed by property name — each schema property is a real, snake_cased COLUMN
 * in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. So renaming `bedrag` to `amount` in the register, on its own,
 * leaves the money in `bedrag` while every read looks at `amount` and finds
 * null. No error, no data loss, and invisible to the test suite, which asserts
 * against fixtures rather than migrated rows.
 *
 * For this app that is money: bedrag columns carry invoice, subsidy, payroll
 * and tax amounts.
 *
 * ALL FIFTY OWNERS MOVE TOGETHER. The map below covers every property name in
 * the cluster, and each was checked to be free of a collision with its English
 * target before being added. A register-scoped step cannot rename a column for
 * one owner and not the rest — the others would silently read null.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so this is reversible
 *     and a re-run is a no-op;
 *   - two sources targeting one destination in a table are REFUSED, not merged;
 *   - nothing is deleted.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename shillinq's Dutch amount columns to their English equivalents.
 *
 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
 */
class RenameDutchColumns implements IRepairStep {
	/**
	 * Slug prefix of the registers in scope.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG_PREFIX = 'portaliq';

	/**
	 * Old snake_case column name => new snake_case column name.
	 *
	 * Snake_case, not camelCase: MagicMapper stores `requestedAmount` as
	 * `requested_amount`, and a camelCase column is exactly what its
	 * de-duplication path then drops.
	 *
	 * @var array<string, string>
	 */
	private const COLUMN_MAP = [
		'aard' => 'nature',
		'termijn' => 'term',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function getName(): string {
		return 'Move portaliq data from the Dutch columns to the English ones';
	}//end getName()

	/**
	 * Run the column migration across every portaliq shard table.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the Dutch-to-English vocabulary
	 *  migration. Pointing this at an existing spec would report conformance to a
	 *  requirement that says nothing about it.
	 */
	public function run(IOutput $output): void {
		$tables = $this->shardTables();
		if ($tables === []) {
			$output->info('RenameDutchColumns: no portaliq shard tables on this install; nothing to do.');
			return;
		}

		$counts = $this->tally(tables: $tables);
		$output->info(
			'RenameDutchColumns: ' . $counts['renamed'] . ' renamed, ' . $counts['copied'] . ' back-filled, '
			. $counts['refused'] . ' refused, across ' . count($tables) . ' shard table(s).'
		);

	}//end run()

	/**
	 * Migrate every table and count the outcomes.
	 *
	 * @param array<int, string> $tables The shard tables.
	 *
	 * @return array{renamed: int, copied: int, refused: int}
	 */
	private function tally(array $tables): array {
		$counts = ['renamed' => 0, 'copied' => 0, 'refused' => 0];
		foreach ($tables as $table) {
			foreach ($this->migrateTable(table: $table) as $outcome) {
				$counts[$outcome]++;
			}
		}

		return $counts;
	}//end tally()

	/**
	 * Move every mapped column of one table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string> One outcome per column that moved or was refused:
	 *                            `renamed`, `copied` or `refused`.
	 */
	private function migrateTable(string $table): array {
		$columns  = $this->columnsOf(table: $table);
		$outcomes = [];
		foreach (self::COLUMN_MAP as $old => $new) {
			if (in_array($old, $columns, true) === true) {
				$outcomes[] = $this->migrateColumn(table: $table, move: ['old' => $old, 'new' => $new, 'columns' => $columns]);
			}
		}

		return array_values(array_filter($outcomes, static fn (?string $outcome): bool => $outcome !== null));
	}//end migrateTable()

	/**
	 * Rename one column, or back-fill it where the new one already exists.
	 *
	 * @param string                                                     $table Table name.
	 * @param array{old: string, new: string, columns: array<int, string>} $move  The source, the destination and the table's columns.
	 *
	 * @return string|null `renamed`, `copied` or `refused`; null when the statement failed.
	 */
	private function migrateColumn(string $table, array $move): ?string {
		if ($this->refuses(table: $table, move: $move) === true) {
			return 'refused';
		}

		$rename = (in_array($move['new'], $move['columns'], true) === false);
		if ($this->exec(sql: $this->moveSql(table: $table, move: ['rename' => $rename] + $move)) === false) {
			return null;
		}

		if ($rename === true) {
			return 'renamed';
		}

		return 'copied';
	}//end migrateColumn()

	/**
	 * Whether a second mapped source competes for the destination; logs the refusal.
	 *
	 * @param string                                                     $table Table name.
	 * @param array{old: string, new: string, columns: array<int, string>} $move  The source, the destination and the table's columns.
	 *
	 * @return bool True when neither source is migrated.
	 */
	private function refuses(string $table, array $move): bool {
		if ($this->hasCollision(columns: $move['columns'], target: $move['new']) === false) {
			return false;
		}

		$this->logger->warning(
			'RenameDutchColumns: two sources target one destination; migrating neither.',
			['table' => $table, 'source' => $move['old'], 'destination' => $move['new']]
		);

		return true;
	}//end refuses()

	/**
	 * The statement that moves one column: a rename, or a back-fill of the
	 * rows where the new column is still empty.
	 *
	 * @param string                                          $table Table name.
	 * @param array{old: string, new: string, rename: bool} $move  The source, the destination and which statement.
	 *
	 * @return string The statement.
	 */
	private function moveSql(string $table, array $move): string {
		$qTable = $this->quote(identifier: $table);
		$qNew   = $this->quote(identifier: $move['new']);
		$qOld   = $this->quote(identifier: $move['old']);
		if ($move['rename'] === true) {
			return 'ALTER TABLE ' . $qTable . ' RENAME COLUMN ' . $qOld . ' TO ' . $qNew;
		}

		return 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld . ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL';
	}//end moveSql()

	/**
	 * Whether another mapped source already targets the same destination here.
	 *
	 * @param array<int, string> $columns Column names present in the table.
	 * @param string $target The destination column name.
	 *
	 * @return bool True when two sources compete for one destination.
	 */
	private function hasCollision(array $columns, string $target): bool {
		$sources = 0;
		foreach (self::COLUMN_MAP as $old => $new) {
			if ($new === $target && in_array($old, $columns, true) === true) {
				$sources++;
			}
		}

		return $sources > 1;
	}//end hasCollision()

	/**
	 * Resolve the shard tables of every register whose slug starts with the prefix.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection:
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(), and calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * Matching anchors on the `openregister_table_` MARKER rather than a computed
	 * prefix, because getTableName('') yields the literal `*PREFIX*` placeholder
	 * which a raw information_schema string never resolves.
	 *
	 * @return array<int, string>
	 */
	private function shardTables(): array {
		$ids = $this->registerIds();
		if ($ids === []) {
			return [];
		}

		$names = $this->openRegisterTableNames();
		if ($names === []) {
			return [];
		}

		return $this->matching(names: $names, ids: $ids);
	}

	/**
	 * The table names that are a shard table of one of the registers.
	 *
	 * @param array<int, string> $names Table names.
	 * @param array<int, mixed>  $ids   Register ids.
	 *
	 * @return array<int, string>
	 */
	private function matching(array $names, array $ids): array {
		$markers = array_map(static fn ($id): string => 'openregister_table_' . ((int)$id) . '_', $ids);
		$tables  = [];
		foreach ($names as $name) {
			foreach ($markers as $marker) {
				if ($this->isShardOf(name: $name, marker: $marker) === true) {
					$tables[] = $name;
				}
			}
		}

		return array_values(array_unique($tables));
	}//end matching()

	/**
	 * Whether a table name is a shard table under one register marker: the
	 * marker followed by nothing but the schema id.
	 *
	 * @param string $name   Table name.
	 * @param string $marker `openregister_table_{register}_`.
	 *
	 * @return bool
	 */
	private function isShardOf(string $name, string $marker): bool {
		$offset = strpos($name, $marker);

		return $offset !== false && ctype_digit(substr($name, ($offset + strlen($marker)))) === true;
	}//end isShardOf()

	/**
	 * Ids of every register whose slug starts with the prefix.
	 *
	 * Split out of shardTables() only to keep that method under phpmd's
	 * cyclomatic-complexity limit; the behaviour is unchanged.
	 *
	 * @return array<int, mixed>
	 */
	private function registerIds(): array {
		try {
			return $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not resolve the registers; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}

	/**
	 * Every table name containing the openregister shard marker.
	 *
	 * @return array<int, string>
	 */
	private function openRegisterTableNames(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not list tables; skipping.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->namesOf(stmt: $stmt, column: 'table_name');
	}//end shardTables()

	/**
	 * List the column names of a table.
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				'RenameDutchColumns: could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		return $this->namesOf(stmt: $stmt, column: 'column_name');
	}//end columnsOf()

	/**
	 * The non-empty values of one column of an executed statement.
	 *
	 * @param IPreparedStatement $stmt   The executed statement.
	 * @param string             $column The column to read.
	 *
	 * @return array<int, string>
	 */
	private function namesOf(IPreparedStatement $stmt, string $column): array {
		$names = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row[$column] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end namesOf()

	/**
	 * Execute one statement, logging and swallowing failure.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameDutchColumns: statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}

	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end class
