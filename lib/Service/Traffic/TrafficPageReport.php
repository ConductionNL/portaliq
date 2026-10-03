<?php

/**
 * Portaliq Traffic Page Report.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * One page's figures over a span of "all visits" daily records. Pure.
 *
 * TWO KINDS OF FIGURE. Views, entrances, exits and the previous and next
 * pages come from fields every daily record has always carried, so every
 * day with a record counts. Sessions, visitors, engaged sessions,
 * referrers and outbound links exist only on rows written since
 * portal-page-traffic; a day whose row lacks them is left out of those
 * sums and counted apart, and when no day carries them the figure is
 * null. A zero there would say "nobody", where the truth is "not counted".
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
 */
class TrafficPageReport {

	/**
	 * The most rows each list of the answer carries.
	 */
	public const TOP = 10;

	/**
	 * The figures only rows written since portal-page-traffic carry.
	 *
	 * @var string[]
	 */
	public const DETAIL = ['sessions', 'visitors', 'engagedSessions', 'referrers', 'outbound'];

	/**
	 * Constructor.
	 *
	 * @param TrafficPagePath $paths Normalises a stored path to a route.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficPagePath $paths = new TrafficPagePath(),
	) {
	}

	/**
	 * Fold the records for one route.
	 *
	 * @param array<int, array<string, mixed>> $records The portal's "all visits" daily records.
	 * @param string                           $route   The page's route, normalised.
	 *
	 * @return array<string, mixed> recordedDays, detailDays, pageViews, entrances, exits,
	 *                              sessions, visitors, engagedSessions, previous, next,
	 *                              referrers, outbound.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
	 */
	public function fold(array $records, string $route): array {
		$acc = ['views' => 0, 'entrances' => 0, 'exits' => 0, 'sessions' => 0, 'visitors' => 0, 'engagedSessions' => 0];
		$lists = ['previous' => [], 'next' => [], 'referrers' => [], 'outbound' => []];
		$detailDays = 0;
		foreach ($records as $record) {
			$rows = $this->rowsFor(record: $record, route: $route);
			foreach ($rows as $row) {
				foreach (['views', 'entrances', 'exits'] as $key) {
					$acc[$key] += $this->int(value: ($row[$key] ?? 0));
				}
			}

			$lists = $this->addTransitions(lists: $lists, rows: ($record['transitions'] ?? []), route: $route);
			if ($this->hasDetail(record: $record, rows: $rows) === false) {
				continue;
			}

			$detailDays++;
			foreach ($rows as $row) {
				foreach (['sessions', 'visitors', 'engagedSessions'] as $key) {
					$acc[$key] += $this->int(value: ($row[$key] ?? 0));
				}

				$lists['referrers'] = $this->addRows(into: $lists['referrers'], rows: ($row['referrers'] ?? []), keys: ['host', 'channel']);
				$lists['outbound'] = $this->addRows(into: $lists['outbound'], rows: ($row['outbound'] ?? []), keys: ['url']);
			}
		}

		$out = [
			'recordedDays' => count($records),
			'detailDays' => $detailDays,
			'pageViews' => $acc['views'],
			'entrances' => $acc['entrances'],
			'exits' => $acc['exits'],
			'sessions' => $acc['sessions'],
			'visitors' => $acc['visitors'],
			'engagedSessions' => $acc['engagedSessions'],
			'previous' => $this->ranked(rows: $lists['previous']),
			'next' => $this->ranked(rows: $lists['next']),
			'referrers' => $this->ranked(rows: $lists['referrers']),
			'outbound' => $this->ranked(rows: $lists['outbound']),
		];
		if ($detailDays === 0) {
			foreach (self::DETAIL as $key) {
				$out[$key] = null;
			}
		}

		return $out;
	}

	/**
	 * A record's page rows for the route. An older row may be keyed by a
	 * path with a trailing slash; it normalises to the same route.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $route  The route.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsFor(array $record, string $route): array {
		$out = [];
		foreach ((array)($record['pages'] ?? []) as $row) {
			if (is_array($row) === true && $this->paths->route(value: (string)($row['path'] ?? '')) === $route) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * Whether a day carries the per-page figures for the route: every row
	 * for it has them, or, with no row for it, the day's rows have them
	 * (the page simply was not viewed that day) or the day has no rows.
	 *
	 * @param array<string, mixed>             $record The record.
	 * @param array<int, array<string, mixed>> $rows   The record's rows for the route.
	 *
	 * @return bool True when the day counts towards the per-page figures.
	 */
	private function hasDetail(array $record, array $rows): bool {
		if ($rows === []) {
			$rows = array_values(array_filter((array)($record['pages'] ?? []), 'is_array'));
			if ($rows === []) {
				return true;
			}
		}

		foreach ($rows as $row) {
			if (array_key_exists('sessions', $row) === false) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Add the transitions into and out of the route.
	 *
	 * @param array<string, array<string, array<string, mixed>>> $lists The accumulators.
	 * @param mixed                                              $rows  The record's transitions.
	 * @param string                                             $route The route.
	 *
	 * @return array<string, array<string, array<string, mixed>>> The accumulators.
	 */
	private function addTransitions(array $lists, mixed $rows, string $route): array {
		if (is_array($rows) === false) {
			return $lists;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$from = $this->paths->route(value: (string)($row['from'] ?? ''));
			$to = $this->paths->route(value: (string)($row['to'] ?? ''));
			$count = $this->int(value: ($row['count'] ?? 0));
			if ($to === $route && $from !== $route) {
				$lists['previous'][$from] ??= ['path' => $from, 'count' => 0];
				$lists['previous'][$from]['count'] += $count;
			}

			if ($from === $route && $to !== $route) {
				$lists['next'][$to] ??= ['path' => $to, 'count' => 0];
				$lists['next'][$to]['count'] += $count;
			}
		}

		return $lists;
	}

	/**
	 * Merge rows by their key fields, summing `count`.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The rows.
	 * @param string[]                            $keys The fields that identify a row.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addRows(array $into, mixed $rows, array $keys): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$fields = [];
			foreach ($keys as $key) {
				$fields[$key] = (string)($row[$key] ?? '');
			}

			$id = implode("\0", $fields);
			$into[$id] ??= $fields + ['count' => 0];
			$into[$id]['count'] += $this->int(value: ($row['count'] ?? 0));
		}

		return $into;
	}

	/**
	 * Rows ranked by count, top ten.
	 *
	 * @param array<string, array<string, mixed>> $rows The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function ranked(array $rows): array {
		$out = array_values($rows);
		usort($out, static fn (array $a, array $b): int => (int)$b['count'] <=> (int)$a['count']);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * A non-negative integer.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int The integer.
	 */
	private function int(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return max(0, (int)$value);
	}
}
