<?php

/**
 * Portaliq Traffic Roll-up Sum.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * One day of a roll-up portal: the sum of its members' "all sessions"
 * records for that day.
 *
 * Pure, like the rollup itself. A member that has no record for the day
 * contributes nothing and is not an error; a roll-up over three portals
 * of which one is quiet is the sum of the other two. Every ranked list is
 * merged by its key (a page by its path, a referrer by host and channel,
 * a goal by its id) so a page that exists on two members is one row with
 * both counts. Rates are re-derived from the summed counts, never
 * averaged: a member with ten sessions and one with ten thousand do not
 * weigh the same.
 *
 * Visitors are summed. Two members may share a visitor, and the sum then
 * counts them twice; the record says so through `rollupOf`, and the
 * alternative (a distinct count) would need the raw events a roll-up
 * portal is defined not to have.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */
class TrafficRollupSum {

	/**
	 * The scalar counters that add up.
	 *
	 * @var string[]
	 */
	private const COUNTERS = ['pageViews', 'sessions', 'visitors', 'engagedSessions'];

	/**
	 * The counters that are null when no member could tell.
	 *
	 * @var string[]
	 */
	private const NULLABLE = ['newVisitors', 'returningVisitors', 'accounts'];

	/**
	 * The maps of value to session count.
	 *
	 * @var string[]
	 */
	private const MAPS = ['events', 'devices', 'browsers', 'os', 'languages', 'regions'];

	/**
	 * The ranked lists: field => [key fields, count fields].
	 *
	 * @var array<string, array{0: string[], 1: string[]}>
	 */
	private const LISTS = [
		'referrers' => [['host', 'channel'], ['count']],
		'campaigns' => [['campaign', 'source', 'medium'], ['sessions']],
		'searches' => [['term'], ['count']],
		'downloads' => [['file'], ['count']],
		'outbound' => [['url'], ['count']],
		'notFound' => [['path'], ['hits']],
		'transitions' => [['from', 'to'], ['count']],
		'errors' => [['message', 'source'], ['hits']],
	];

	/**
	 * Sum the members' records into the roll-up portal's day.
	 *
	 * @param string                           $portal       The roll-up portal's slug.
	 * @param string                           $date         The UTC day.
	 * @param string[]                         $members      The member slugs, as configured.
	 * @param array<int, array<string, mixed>> $records      The members' "all sessions" records for the day, any subset.
	 * @param string                           $aggregatedAt When this record was computed.
	 *
	 * @return array<string, mixed> The `portalTrafficDaily` fields.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function sum(string $portal, string $date, array $members, array $records, string $aggregatedAt): array {
		$out = [
			'portal' => $portal,
			'date' => $date,
			'segment' => '',
			'rollupOf' => array_values($members),
			'members' => count($records),
		];
		foreach (self::COUNTERS as $key) {
			$out[$key] = 0;
		}

		foreach (self::NULLABLE as $key) {
			$out[$key] = null;
		}

		foreach (self::MAPS as $key) {
			$out[$key] = [];
		}

		$engagementSeconds = 0.0;
		$converted = 0.0;
		$last = '';
		$lists = array_fill_keys(array_keys(self::LISTS), []);
		$pages = [];
		$goals = [];
		$funnels = [];
		$forms = [];
		$dimensions = [];
		$emails = ['opens' => 0, 'clicks' => 0];
		foreach ($records as $record) {
			foreach (self::COUNTERS as $key) {
				$out[$key] += $this->int($record[$key] ?? 0);
			}

			foreach (self::NULLABLE as $key) {
				if (is_int($record[$key] ?? null) === true || is_float($record[$key] ?? null) === true) {
					$out[$key] = ($out[$key] ?? 0) + $this->int($record[$key]);
				}
			}

			foreach (self::MAPS as $key) {
				$out[$key] = $this->addMap(into: $out[$key], from: ($record[$key] ?? []));
			}

			foreach (self::LISTS as $key => [$keys, $counts]) {
				$lists[$key] = $this->addList(into: $lists[$key], rows: ($record[$key] ?? []), keys: $keys, counts: $counts);
			}

			$sessions = $this->int($record['sessions'] ?? 0);
			$engagementSeconds += $this->float($record['avgEngagementSeconds'] ?? 0) * $sessions;
			$converted += $this->float($record['conversionRate'] ?? 0) * $sessions;
			$last = max($last, (string)($record['lastEventAt'] ?? ''));
			$pages = $this->addPages(into: $pages, rows: ($record['pages'] ?? []));
			$goals = $this->addGoals(into: $goals, rows: ($record['goals'] ?? []));
			$funnels = $this->addFunnels(into: $funnels, rows: ($record['funnels'] ?? []));
			$forms = $this->addForms(into: $forms, rows: ($record['forms'] ?? []));
			$dimensions = $this->addDimensions(into: $dimensions, from: ($record['customDimensions'] ?? null));
			$emails['opens'] += $this->int($record['emails']['opens'] ?? 0);
			$emails['clicks'] += $this->int($record['emails']['clicks'] ?? 0);
		}

		$sessions = (int)$out['sessions'];
		$out['avgEngagementSeconds'] = ($sessions > 0) ? round($engagementSeconds / $sessions, 1) : 0.0;
		$out['bounceRate'] = ($sessions > 0) ? round(($sessions - (int)$out['engagedSessions']) / $sessions, 3) : 0.0;
		$out['conversionRate'] = ($sessions > 0) ? round($converted / $sessions, 3) : 0.0;
		$out['pages'] = $this->finishPages(pages: $pages);
		foreach (self::LISTS as $key => [, $counts]) {
			$out[$key] = $this->ranked(rows: $lists[$key], countKey: $counts[0]);
		}

		$out['goals'] = array_values($goals);
		$out['funnels'] = $this->finishFunnels(funnels: $funnels);
		$out['forms'] = $this->finishForms(forms: $forms);
		$out['customDimensions'] = ($dimensions === []) ? null : $dimensions;
		$out['emails'] = $emails;
		$out['aggregatedAt'] = $aggregatedAt;
		$out['lastEventAt'] = $last;

		return $out;
	}

	/**
	 * Add one value-to-count map into another.
	 *
	 * @param array<string, int> $into The accumulator.
	 * @param mixed              $from The record's map.
	 *
	 * @return array<string, int> The sum, sorted by value.
	 */
	private function addMap(array $into, mixed $from): array {
		if (is_array($from) === false) {
			return $into;
		}

		foreach ($from as $value => $count) {
			$into[(string)$value] = ($into[(string)$value] ?? 0) + $this->int($count);
		}

		ksort($into);

		return $into;
	}

	/**
	 * Merge ranked rows by their key fields, summing their count fields.
	 *
	 * @param array<string, array<string, mixed>> $into   The accumulator, keyed by the joined key fields.
	 * @param mixed                               $rows   The record's rows.
	 * @param string[]                            $keys   The fields that identify a row.
	 * @param string[]                            $counts The fields that add up.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addList(array $into, mixed $rows, array $keys, array $counts): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$id = implode("\0", array_map(static fn (string $key): string => (string)($row[$key] ?? ''), $keys));
			if ($id === str_repeat("\0", max(0, count($keys) - 1))) {
				continue;
			}

			if (isset($into[$id]) === false) {
				$into[$id] = [];
				foreach ($keys as $key) {
					$into[$id][$key] = (string)($row[$key] ?? '');
				}

				foreach ($counts as $count) {
					$into[$id][$count] = 0;
				}

				// A list may carry more than keys and counts (an error's
				// pages); the first row's extra fields are kept as is.
				foreach ($row as $field => $value) {
					if (array_key_exists($field, $into[$id]) === false) {
						$into[$id][$field] = $value;
					}
				}
			}

			foreach ($counts as $count) {
				$into[$id][$count] += $this->int($row[$count] ?? 0);
			}

			if (is_array($row['pages'] ?? null) === true && is_array($into[$id]['pages'] ?? null) === true) {
				$into[$id]['pages'] = array_values(array_unique(array_merge($into[$id]['pages'], $row['pages'])));
			}
		}

		return $into;
	}

	/**
	 * Rows ranked by a count, top hundred.
	 *
	 * @param array<string, array<string, mixed>> $rows     The accumulator.
	 * @param string                              $countKey The count field.
	 *
	 * @return array<int, array<string, mixed>> The ranked rows.
	 */
	private function ranked(array $rows, string $countKey): array {
		$out = array_values($rows);
		usort($out, static fn (array $a, array $b): int => (int)$b[$countKey] <=> (int)$a[$countKey]);

		return array_slice($out, 0, 100);
	}

	/**
	 * Merge page rows by path.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's pages.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addPages(array $into, mixed $rows): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || trim((string)($row['path'] ?? '')) === '') {
				continue;
			}

			$path = (string)$row['path'];
			$page = $into[$path] ?? ['path' => $path, 'views' => 0, 'entrances' => 0, 'exits' => 0, 'seconds' => 0.0];
			$views = $this->int($row['views'] ?? 0);
			$page['views'] += $views;
			$page['entrances'] += $this->int($row['entrances'] ?? 0);
			$page['exits'] += $this->int($row['exits'] ?? 0);
			$page['seconds'] += $this->float($row['avgEngagementSeconds'] ?? 0) * $views;
			$into[$path] = $page;
		}

		return $into;
	}

	/**
	 * The merged pages with their mean engagement, ranked by views.
	 *
	 * @param array<string, array<string, mixed>> $pages The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function finishPages(array $pages): array {
		$out = [];
		foreach ($pages as $page) {
			$views = (int)$page['views'];
			$out[] = [
				'path' => $page['path'],
				'views' => $views,
				'entrances' => $page['entrances'],
				'exits' => $page['exits'],
				'avgEngagementSeconds' => ($views > 0) ? round((float)$page['seconds'] / $views, 1) : 0.0,
			];
		}

		usort($out, static fn (array $a, array $b): int => (int)$b['views'] <=> (int)$a['views']);

		return array_slice($out, 0, 100);
	}

	/**
	 * Merge goal rows by id.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's goals.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addGoals(array $into, mixed $rows): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || (string)($row['id'] ?? '') === '') {
				continue;
			}

			$id = (string)$row['id'];
			$goal = $into[$id] ?? ['id' => $id, 'name' => (string)($row['name'] ?? $id), 'conversions' => 0, 'completions' => 0, 'value' => 0.0];
			$goal['conversions'] += $this->int($row['conversions'] ?? 0);
			$goal['completions'] += $this->int($row['completions'] ?? 0);
			$goal['value'] = round($goal['value'] + $this->float($row['value'] ?? 0), 2);
			$into[$id] = $goal;
		}

		return $into;
	}

	/**
	 * Merge funnel rows by id, step by step.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's funnels.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addFunnels(array $into, mixed $rows): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || (string)($row['id'] ?? '') === '') {
				continue;
			}

			$id = (string)$row['id'];
			$funnel = $into[$id] ?? ['id' => $id, 'name' => (string)($row['name'] ?? $id), 'steps' => []];
			foreach (array_values((array)($row['steps'] ?? [])) as $index => $step) {
				if (is_array($step) === false) {
					continue;
				}

				$funnel['steps'][$index] = [
					'name' => (string)($step['name'] ?? ($funnel['steps'][$index]['name'] ?? '')),
					'sessions' => (int)($funnel['steps'][$index]['sessions'] ?? 0) + $this->int($step['sessions'] ?? 0),
				];
			}

			$into[$id] = $funnel;
		}

		return $into;
	}

	/**
	 * The merged funnels with each step's drop-off re-derived.
	 *
	 * @param array<string, array<string, mixed>> $funnels The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function finishFunnels(array $funnels): array {
		$out = [];
		foreach ($funnels as $funnel) {
			$steps = [];
			$previous = null;
			foreach ($funnel['steps'] as $step) {
				$sessions = (int)$step['sessions'];
				$dropOff = 0.0;
				if ($previous !== null && $previous > 0) {
					$dropOff = round(($previous - $sessions) / $previous, 3);
				}

				$steps[] = ['name' => $step['name'], 'sessions' => $sessions, 'dropOff' => $dropOff];
				$previous = $sessions;
			}

			$out[] = ['id' => $funnel['id'], 'name' => $funnel['name'], 'steps' => $steps];
		}

		return $out;
	}

	/**
	 * Merge form rows by form id, fields by field id.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's forms.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addForms(array $into, mixed $rows): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || (string)($row['formId'] ?? '') === '') {
				continue;
			}

			$id = (string)$row['formId'];
			$form = $into[$id] ?? ['formId' => $id, 'starts' => 0, 'submits' => 0, 'abandons' => 0, 'fields' => []];
			foreach (['starts', 'submits', 'abandons'] as $key) {
				$form[$key] += $this->int($row[$key] ?? 0);
			}

			foreach ((array)($row['fields'] ?? []) as $field) {
				if (is_array($field) === false || (string)($field['fieldId'] ?? '') === '') {
					continue;
				}

				$fieldId = (string)$field['fieldId'];
				$sum = $form['fields'][$fieldId] ?? ['fieldId' => $fieldId, 'ms' => 0, 'members' => 0, 'abandonedHere' => 0];
				$sum['ms'] += $this->int($field['avgMs'] ?? 0);
				$sum['members'] += 1;
				$sum['abandonedHere'] += $this->int($field['abandonedHere'] ?? 0);
				$form['fields'][$fieldId] = $sum;
			}

			$into[$id] = $form;
		}

		return $into;
	}

	/**
	 * The merged forms with their completion rate and mean field times.
	 *
	 * @param array<string, array<string, mixed>> $forms The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function finishForms(array $forms): array {
		$out = [];
		foreach ($forms as $form) {
			$fields = [];
			foreach ($form['fields'] as $field) {
				$fields[] = [
					'fieldId' => $field['fieldId'],
					'avgMs' => ($field['members'] > 0) ? (int)round($field['ms'] / $field['members']) : 0,
					'abandonedHere' => $field['abandonedHere'],
				];
			}

			$starts = (int)$form['starts'];
			$out[] = [
				'formId' => $form['formId'],
				'starts' => $starts,
				'submits' => $form['submits'],
				'abandons' => $form['abandons'],
				'completionRate' => ($starts > 0) ? round($form['submits'] / $starts, 3) : 0.0,
				'fields' => $fields,
			];
		}

		return $out;
	}

	/**
	 * Add nested custom dimension counts.
	 *
	 * @param array<string, array<string, int>> $into The accumulator.
	 * @param mixed                             $from The record's map.
	 *
	 * @return array<string, array<string, int>> The accumulator.
	 */
	private function addDimensions(array $into, mixed $from): array {
		if (is_array($from) === false) {
			return $into;
		}

		foreach ($from as $id => $map) {
			if (is_array($map) === false) {
				continue;
			}

			$into[(string)$id] = $this->addMap(into: ($into[(string)$id] ?? []), from: $map);
		}

		return $into;
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

	/**
	 * A non-negative float.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float The float.
	 */
	private function float(mixed $value): float {
		if (is_numeric($value) === false) {
			return 0.0;
		}

		return max(0.0, (float)$value);
	}
}
