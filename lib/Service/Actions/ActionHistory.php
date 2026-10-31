<?php

/**
 * Portaliq Action History
 *
 * The history of one action, read from OpenRegister's audit trail of the
 * object (ADR-022: no own history table).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Actions
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
 * @spec openspec/changes/personal-action-list/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Actions;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Portal writes reach OpenRegister without a Nextcloud user, so the trail
 * itself names no resident. Every portal write stamps `editedBy`; replaying
 * the changes oldest first tells who made each one.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
 */
class ActionHistory {

	private const TRAIL_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * The fields whose change is a line of the history.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['title', 'description', 'kind', 'status', 'endDate', 'assignee'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's audit-trail mapper, which may be absent.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The lines of an action's history, newest first. Each line is
	 * `{at, who, kind, field, from, to}`: kind `created` (with `to` the end
	 * date), `status`, `file` (`to` the name), `fileRemoved` (`from` the
	 * name) or `field`. Subject references in `who` and in an assignee's
	 * `from` and `to` are handed to `$nameOf`.
	 *
	 * @param string $uuid The action's uuid.
	 * @param callable(string): string $nameOf A person's name by subject reference.
	 *
	 * @return array<int, array{at: string, who: string, kind: string, field: string, from: string, to: string}>
	 *
	 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md#requirement-an-action-can-be-shared-with-an-approved-contact-req-ral-002
	 */
	public function lines(string $uuid, callable $nameOf): array {
		$lines = [];
		$actor = '';
		foreach ($this->changes(uuid: $uuid) as $index => $row) {
			$changed = (array)($row['changed'] ?? []);
			if (is_array($changed['editedBy'] ?? null) === true && is_string($changed['editedBy']['new'] ?? null) === true) {
				$actor = $changed['editedBy']['new'];
			}

			$base = ['at' => (string)($row['created'] ?? ''), 'who' => $nameOf($actor)];
			if ($index === 0) {
				$lines[] = $base + ['kind' => 'created', 'field' => '', 'from' => '', 'to' => self::text(value: ($changed['endDate']['new'] ?? ''))];
				continue;
			}

			foreach ($this->rowLines(changed: $changed, nameOf: $nameOf) as $line) {
				$lines[] = $base + $line;
			}
		}

		return array_reverse($lines);
	}//end lines()

	/**
	 * The lines one audit row makes.
	 *
	 * @param array<string, mixed> $changed The row's `{field: {old, new}}`.
	 * @param callable(string): string $nameOf A person's name by subject reference.
	 *
	 * @return array<int, array{kind: string, field: string, from: string, to: string}>
	 */
	private function rowLines(array $changed, callable $nameOf): array {
		$lines = [];
		foreach (self::FIELDS as $field) {
			if (is_array($changed[$field] ?? null) === false) {
				continue;
			}

			$from = self::text(value: ($changed[$field]['old'] ?? ''));
			$to   = self::text(value: ($changed[$field]['new'] ?? ''));
			if ($field === 'assignee') {
				$from = $nameOf($from);
				$to   = $nameOf($to);
			}

			$kind = 'field';
			if ($field === 'status') {
				$kind = 'status';
			}

			$lines[] = ['kind' => $kind, 'field' => $field, 'from' => $from, 'to' => $to];
		}

		if (is_array($changed['fileName'] ?? null) === true) {
			$from    = self::text(value: ($changed['fileName']['old'] ?? ''));
			$to      = self::text(value: ($changed['fileName']['new'] ?? ''));
			$kind    = 'file';
			if ($to === '') {
				$kind = 'fileRemoved';
			}

			$lines[] = ['kind' => $kind, 'field' => 'file', 'from' => $from, 'to' => $to];
		}

		return $lines;
	}//end rowLines()

	/**
	 * The audit rows of the object, oldest first, or none when the trail
	 * cannot be read.
	 *
	 * @param string $uuid The action's uuid.
	 *
	 * @return array<int, array{created: string, changed: array}>
	 */
	private function changes(string $uuid): array {
		if ($uuid === '') {
			return [];
		}

		try {
			$mapper = $this->container->get(self::TRAIL_MAPPER);
			if (method_exists($mapper, 'findChangesForObject') === false) {
				return [];
			}

			$rows = $mapper->findChangesForObject($uuid, 200);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: action history unavailable', ['reason' => $e->getMessage()]);
			return [];
		}

		return array_values(array_filter((array)$rows, 'is_array'));
	}//end changes()

	/**
	 * A stored value as text: a date is shortened to its day.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		$text = (string)$value;
		if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $text) === 1) {
			return substr($text, 0, 10);
		}

		return $text;
	}//end text()
}//end class
