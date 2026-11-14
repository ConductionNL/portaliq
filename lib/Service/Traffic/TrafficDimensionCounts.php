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
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The dimension tables of a rollup: sessions per referrer, campaign,
 * device, browser, operating system, language and region, and events per
 * search term, downloaded file and outbound link. Pure.
 *
 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
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
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function perSession(array $sessions, array $keys, array $names, string $countKey): array {
		$out = [];
		foreach ((new TrafficFirstEventTally())->tally(sessions: $sessions, keys: $keys) as $entry) {
			$row = $this->namedRow(values: $entry['values'], names: $names);
			$row[$countKey] = $entry['count'];
			$out[] = $row;
		}

		usort($out, static fn (array $a, array $b): int => (int)$b[$countKey] <=> (int)$a[$countKey]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * A row with the values written under their names; a name without a
	 * value gets ''.
	 *
	 * @param array<int, string> $values The values, in key order.
	 * @param array<int, string> $names  The row keys.
	 *
	 * @return array<string, string> The row.
	 */
	private function namedRow(array $values, array $names): array {
		$row = [];
		foreach ($names as $index => $name) {
			$row[$name] = $values[$index] ?? '';
		}

		return $row;
	}

	/**
	 * Sessions per value of one dimension, as a map.
	 *
	 * The value is the first NON-EMPTY one across the session's events, not
	 * the first event's. A dimension such as the region is derived at ingest
	 * from the portal's configuration of that moment, so a session that
	 * started before an operator switched geography on has empty early
	 * events and a filled later one; reading only the first event reported
	 * that session as unknown for the whole day, and the Visitors widget
	 * showed no region while the events plainly carried one.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param string                           $key      The event field.
	 *
	 * @return array<string, int> Value => sessions, sorted by value.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function perSessionMap(array $sessions, string $key): array {
		$map = [];
		foreach ($sessions as $session) {
			$value = '';
			foreach (($session['events'] ?? []) as $event) {
				$value = trim((string)($event[$key] ?? ''));
				if ($value !== '') {
					break;
				}
			}

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
	 * @param string                           $countKey The row key for the count.
	 *
	 * @return array<int, array<string, mixed>> Rows of [label => value, countKey => n], ranked.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function perEvent(array $sessions, string $name, array $keys, string $label, string $countKey = 'count'): array {
		$counts = [];
		foreach ($this->eventsNamed(sessions: $sessions, name: $name) as $event) {
			$value = $this->firstOf(event: $event, keys: $keys);
			if ($value !== '') {
				$counts[$value] = ($counts[$value] ?? 0) + 1;
			}
		}

		$out = [];
		foreach ($this->ranked(counts: $counts) as [$value, $count]) {
			$out[] = [$label => $value, $countKey => $count];
		}

		return $out;
	}

	/**
	 * Every event of the given name, session by session.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param string                           $name     The event name.
	 *
	 * @return array<int, array<string, mixed>> The events, in order.
	 */
	private function eventsNamed(array $sessions, string $name): array {
		$events = [];
		foreach ($sessions as $session) {
			foreach ($session['events'] as $event) {
				if (($event['name'] ?? '') === $name) {
					$events[] = $event;
				}
			}
		}

		return $events;
	}

	/**
	 * Counted values as `[value, count]` pairs, most counted first, ties by
	 * value, cut to the top.
	 *
	 * @param array<int|string, int> $counts Value => count.
	 *
	 * @return array<int, array{0: string, 1: int}> The ranked pairs.
	 */
	private function ranked(array $counts): array {
		$pairs = [];
		foreach ($counts as $value => $count) {
			$pairs[] = [(string)$value, $count];
		}

		usort($pairs, static fn (array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

		return array_slice($pairs, 0, self::TOP);
	}

	/**
	 * The searches that found nothing (portal-traffic-zero-result-searches):
	 * per term, how many `search` events of the day carried a result count of
	 * the integer 0, ranked. An event with a term and no readable count is not
	 * a zero: it is counted apart, so the gap shows. The count is read from
	 * `results`, else `params.results`, and a string ("0") is not a count.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array{rows: array<int, array{term: string, count: int}>, withoutCount: int} The ranked terms, and the searches with no count.
	 *
	 * @spec openspec/changes/portal-traffic-zero-result-searches/specs/portal-traffic-reporting/spec.md#requirement-the-roll-up-counts-searches-that-found-nothing-req-pzr-001
	 */
	public function zeroResults(array $sessions): array {
		$searches = $this->searches(sessions: $sessions);
		$without = count(array_filter($searches, static fn (array $search): bool => $search[1] === null));
		$zero = array_filter($searches, static fn (array $search): bool => $search[1] === 0);
		$rows = [];
		foreach ($this->ranked(counts: array_count_values(array_column($zero, 0))) as [$term, $count]) {
			$rows[] = ['term' => $term, 'count' => $count];
		}

		return ['rows' => $rows, 'withoutCount' => $without];
	}//end zeroResults()

	/**
	 * The day's searches that carried a term, as `[term, result count]`
	 * pairs, the count null when the event carried none.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array<int, array{0: string, 1: int|null}> The searches, in order.
	 */
	private function searches(array $sessions): array {
		$searches = [];
		foreach ($this->eventsNamed(sessions: $sessions, name: 'search') as $event) {
			$term = $this->firstOf(event: $event, keys: ['searchTerm', 'params.search_term']);
			if ($term !== '') {
				$searches[] = [$term, $this->resultCount(event: $event)];
			}
		}

		return $searches;
	}

	/**
	 * The result count of a search event as an integer, or null when it
	 * carries none: a whole number only, never a string.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return int|null The count.
	 */
	private function resultCount(array $event): ?int {
		$value = ($event['results'] ?? ($event['params']['results'] ?? null));
		if (is_int($value) === true && $value >= 0) {
			return $value;
		}

		if (is_float($value) === true && $value >= 0 && floor($value) === $value) {
			return (int)$value;
		}

		return null;
	}//end resultCount()

	/**
	 * The custom dimensions (portal-traffic-outcomes): per declared id, a
	 * map of value to count. A session-scoped dimension counts each
	 * session once, by the first non-empty value it carried; an
	 * event-scoped one counts every event that carried the value.
	 *
	 * A declared id that nothing carried is LEFT OUT rather than written as
	 * an empty map: the object store refuses an empty object for a typed
	 * property, and the Traffic page lists declared dimensions from the
	 * portal record, so "declared but never set" still shows.
	 *
	 * @param array<int, array<string, mixed>>   $sessions    The sessions.
	 * @param array<int, array<string, string>>  $definitions The declared dimensions, `{id, name, scope}`.
	 *
	 * @return array<string, array<string, int>> Id => (value => count), values sorted.
	 *
	 * @spec openspec/specs/portal-traffic-outcomes/spec.md#requirement-custom-dimensions-must-be-declared-before-they-are-stored
	 */
	public function custom(array $sessions, array $definitions): array {
		$out = [];
		foreach ($definitions as $definition) {
			$map = $this->dimensionMap(sessions: $sessions, definition: $definition);
			if ($map === []) {
				continue;
			}

			ksort($map);
			$out[(string)($definition['id'] ?? '')] = array_slice($map, 0, self::TOP, true);
		}

		return $out;
	}

	/**
	 * One custom dimension's value => count map, unsorted: every carrying
	 * event counts, or only a session's first value when it is
	 * session-scoped.
	 *
	 * @param array<int, array<string, mixed>> $sessions   The sessions.
	 * @param array<string, string>            $definition The declared dimension, `{id, name, scope}`.
	 *
	 * @return array<string, int> Value => count.
	 */
	private function dimensionMap(array $sessions, array $definition): array {
		$field = 'params.cd_' . (string)($definition['id'] ?? '');
		$perSession = (($definition['scope'] ?? 'event') === 'session');
		$map = [];
		foreach ($sessions as $session) {
			$values = $this->valuesIn(session: $session, field: $field);
			if ($perSession === true) {
				$values = array_slice($values, 0, 1);
			}

			foreach ($values as $value) {
				$map[$value] = ($map[$value] ?? 0) + 1;
			}
		}

		return $map;
	}

	/**
	 * The non-empty values of one field across a session's events, in order.
	 *
	 * @param array<string, mixed> $session The session.
	 * @param string               $field   The field, `params.x` reaching into params.
	 *
	 * @return array<int, string> The values.
	 */
	private function valuesIn(array $session, string $field): array {
		$values = [];
		foreach (($session['events'] ?? []) as $event) {
			$value = $this->firstOf(event: $event, keys: [$field]);
			if ($value !== '') {
				$values[] = $value;
			}
		}

		return $values;
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
