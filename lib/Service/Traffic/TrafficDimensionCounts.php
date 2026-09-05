<?php

/**
 * Portaliq Traffic Dimension Counts.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The dimension tables of a rollup: sessions per referrer, campaign,
 * device, browser, operating system, language and region, and events per
 * search term, downloaded file and outbound link. Pure.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */
class TrafficDimensionCounts {

	/**
	 * The most entries a ranked list carries. A page that is not in the top
	 * hundred is not one anybody will read a report for, and a record that
	 * grows with the site's page count is a record that stops fitting.
	 */
	private const TOP = 100;

	/**
	 * Sessions grouped by a tuple of dimensions read off each session's
	 * first event, as a ranked list of rows.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param array<int, string>               $keys     The event fields to read.
	 * @param array<int, string>               $names    The row keys to write them under.
	 * @param string                           $countKey The row key for the count.
	 *
	 * @return array<int, array<string, mixed>> The rows, ranked by count.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function perSession(array $sessions, array $keys, array $names, string $countKey): array {
		$rows = [];
		$counts = [];
		foreach ($sessions as $session) {
			$first = $session['events'][0] ?? [];
			$values = [];
			$any = false;
			foreach ($keys as $key) {
				$value = trim((string)($first[$key] ?? ''));
				$any = $any || ($value !== '');
				$values[] = $value;
			}

			if ($any === false) {
				continue;
			}

			$id = implode("\0", $values);
			$counts[$id] = ($counts[$id] ?? 0) + 1;
			$rows[$id] = $values;
		}

		$out = [];
		foreach ($rows as $id => $values) {
			$row = [];
			foreach ($names as $index => $name) {
				$row[$name] = $values[$index] ?? '';
			}

			$row[$countKey] = $counts[$id];
			$out[] = $row;
		}

		usort($out, static fn (array $a, array $b): int => (int)$b[$countKey] <=> (int)$a[$countKey]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * Sessions per value of one dimension, read off each session's first
	 * event, as a map.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param string                           $key      The event field.
	 *
	 * @return array<string, int> Value => sessions, sorted by value.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function perSessionMap(array $sessions, string $key): array {
		$map = [];
		foreach ($sessions as $session) {
			$value = trim((string)($session['events'][0][$key] ?? ''));
			if ($value === '') {
				continue;
			}

			$map[$value] = ($map[$value] ?? 0) + 1;
		}

		ksort($map);

		return $map;
	}

	/**
	 * Occurrences of one event name grouped by the first non-empty of some
	 * fields (a `params.x` key reads into the params map).
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param string                           $name     The event name.
	 * @param array<int, string>               $keys     Fields to try, in order.
	 * @param string                           $label    The row key for the value.
	 *
	 * @return array<int, array<string, mixed>> Rows of [label => value, count => n], ranked.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function perEvent(array $sessions, string $name, array $keys, string $label): array {
		$counts = [];
		foreach ($sessions as $session) {
			foreach ($session['events'] as $event) {
				if (($event['name'] ?? '') !== $name) {
					continue;
				}

				$value = $this->firstOf(event: $event, keys: $keys);
				if ($value === '') {
					continue;
				}

				$counts[$value] = ($counts[$value] ?? 0) + 1;
			}
		}

		$out = [];
		foreach ($counts as $value => $count) {
			$out[] = [$label => (string)$value, 'count' => $count];
		}

		usort($out, static fn (array $a, array $b): int => [$b['count'], $a[$label]] <=> [$a['count'], $b[$label]]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * The first non-empty of several event fields.
	 *
	 * @param array<string, mixed> $event The event.
	 * @param array<int, string>   $keys  Fields, `params.x` reaching into params.
	 *
	 * @return string The value, or ''.
	 */
	private function firstOf(array $event, array $keys): string {
		foreach ($keys as $key) {
			$value = $event[$key] ?? null;
			if (str_starts_with($key, 'params.') === true) {
				$value = $event['params'][substr($key, 7)] ?? null;
			}

			if (is_scalar($value) === true && trim((string)$value) !== '') {
				return trim((string)$value);
			}
		}

		return '';
	}
}
