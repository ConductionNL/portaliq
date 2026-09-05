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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- one merge over
 * every shape the daily record has (maps, ranked lists, pages, goals,
 * funnels, forms, nested dimensions); each shape is its own small
 * method, and the sum of them is what a roll-up is.
 */
class TrafficRollupSum {

	/**
	 * Constructor.
	 *
	 * @param TrafficExperiments $experiments Re-derives a summed experiment's verdict.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficExperiments $experiments = new TrafficExperiments(),
	) {
	}

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
		$acc = $this->empty();
		foreach ($records as $record) {
			$acc = $this->addRecord(acc: $acc, record: $record);
		}

		return $this->finish(acc: $acc, portal: $portal, date: $date, members: $members, count: count($records), aggregatedAt: $aggregatedAt);
	}

	/**
	 * The empty accumulator.
	 *
	 * @return array<string, mixed> Every counter at zero, every list empty.
	 */
	private function empty(): array {
		$acc = ['seconds' => 0.0, 'converted' => 0.0, 'last' => '', 'pages' => [], 'goals' => [], 'funnels' => [], 'forms' => []];
		$acc['dimensions'] = [];
		$acc['experiments'] = [];
		$acc['emails'] = ['opens' => 0, 'clicks' => 0];
		$acc['lists'] = array_fill_keys(array_keys(self::LISTS), []);
		foreach (self::COUNTERS as $key) {
			$acc[$key] = 0;
		}

		foreach (self::NULLABLE as $key) {
			$acc[$key] = null;
		}

		foreach (self::MAPS as $key) {
			$acc[$key] = [];
		}

		return $acc;
	}

	/**
	 * Add one member's record to the accumulator.
	 *
	 * @param array<string, mixed> $acc    The accumulator.
	 * @param array<string, mixed> $record The member's record.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addRecord(array $acc, array $record): array {
		foreach (self::COUNTERS as $key) {
			$acc[$key] += $this->int(value: ($record[$key] ?? 0));
		}

		foreach (self::NULLABLE as $key) {
			if (is_numeric($record[$key] ?? null) === true) {
				$acc[$key] = ($acc[$key] ?? 0) + $this->int(value: $record[$key]);
			}
		}

		foreach (self::MAPS as $key) {
			$acc[$key] = $this->addMap(into: $acc[$key], from: ($record[$key] ?? []));
		}

		foreach (self::LISTS as $key => [$keys, $counts]) {
			$acc['lists'][$key] = $this->addList(into: $acc['lists'][$key], rows: ($record[$key] ?? []), keys: $keys, counts: $counts);
		}

		$sessions = $this->int(value: ($record['sessions'] ?? 0));
		$acc['seconds'] += $this->float(value: ($record['avgEngagementSeconds'] ?? 0)) * $sessions;
		$acc['converted'] += $this->float(value: ($record['conversionRate'] ?? 0)) * $sessions;
		$acc['last'] = max($acc['last'], (string)($record['lastEventAt'] ?? ''));
		$acc['pages'] = $this->addPages(into: $acc['pages'], rows: ($record['pages'] ?? []));
		$acc['goals'] = $this->addGoals(into: $acc['goals'], rows: ($record['goals'] ?? []));
		$acc['funnels'] = $this->addFunnels(into: $acc['funnels'], rows: ($record['funnels'] ?? []));
		$acc['forms'] = $this->addForms(into: $acc['forms'], rows: ($record['forms'] ?? []));
		$acc['dimensions'] = $this->addDimensions(into: $acc['dimensions'], from: ($record['customDimensions'] ?? null));
		$acc['experiments'] = $this->addExperiments(into: $acc['experiments'], rows: ($record['experiments'] ?? []));
		$acc['emails']['opens'] += $this->int(value: ($record['emails']['opens'] ?? 0));
		$acc['emails']['clicks'] += $this->int(value: ($record['emails']['clicks'] ?? 0));

		return $acc;
	}

	/**
	 * The accumulator as the roll-up's record.
	 *
	 * @param array<string, mixed> $acc          The accumulator.
	 * @param string               $portal       The roll-up portal's slug.
	 * @param string               $date         The UTC day.
	 * @param string[]             $members      The member slugs.
	 * @param int                  $count        How many members had a record.
	 * @param string               $aggregatedAt When this record was computed.
	 *
	 * @return array<string, mixed> The `portalTrafficDaily` fields.
	 */
	private function finish(array $acc, string $portal, string $date, array $members, int $count, string $aggregatedAt): array {
		$sessions = (int)$acc['sessions'];
		$out = ['portal' => $portal, 'date' => $date, 'segment' => '', 'rollupOf' => array_values($members), 'members' => $count];
		foreach (array_merge(self::COUNTERS, self::NULLABLE, self::MAPS) as $key) {
			$out[$key] = $acc[$key];
		}

		$out['avgEngagementSeconds'] = $this->rate(numerator: (float)$acc['seconds'], denominator: $sessions, decimals: 1);
		$out['bounceRate'] = $this->rate(numerator: (float)($sessions - (int)$acc['engagedSessions']), denominator: $sessions, decimals: 3);
		$out['conversionRate'] = $this->rate(numerator: (float)$acc['converted'], denominator: $sessions, decimals: 3);
		$out['pages'] = $this->finishPages(pages: $acc['pages']);
		foreach (self::LISTS as $key => [, $counts]) {
			$out[$key] = $this->ranked(rows: $acc['lists'][$key], countKey: $counts[0]);
		}

		$out['goals'] = array_values($acc['goals']);
		$out['funnels'] = $this->finishFunnels(funnels: $acc['funnels']);
		$out['forms'] = $this->finishForms(forms: $acc['forms']);
		$out['customDimensions'] = $acc['dimensions'];
		if ($acc['dimensions'] === []) {
			$out['customDimensions'] = null;
		}

		$out['experiments'] = $this->finishExperiments(experiments: $acc['experiments']);
		// A roll-up carries no heatmap: its members' pages are on different
		// sites, and a click grid summed across sites draws nothing true.
		$out['heatmaps'] = [];
		$out['emails'] = $acc['emails'];
		$out['aggregatedAt'] = $aggregatedAt;
		$out['lastEventAt'] = $acc['last'];

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
			$into[(string)$value] = ($into[(string)$value] ?? 0) + $this->int(value: $count);
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
			if (trim($id, "\0") === '') {
				continue;
			}

			$into[$id] = $this->mergeRow(into: ($into[$id] ?? $this->newRow(row: $row, keys: $keys, counts: $counts)), row: $row, counts: $counts);
		}

		return $into;
	}

	/**
	 * A fresh accumulator row: the key fields, the counts at zero, and any
	 * other field (an error's pages) as the first row had it.
	 *
	 * @param array<string, mixed> $row    The first row with this key.
	 * @param string[]             $keys   The fields that identify a row.
	 * @param string[]             $counts The fields that add up.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function newRow(array $row, array $keys, array $counts): array {
		$out = [];
		foreach ($keys as $key) {
			$out[$key] = (string)($row[$key] ?? '');
		}

		foreach ($counts as $count) {
			$out[$count] = 0;
		}

		foreach ($row as $field => $value) {
			if (array_key_exists($field, $out) === false) {
				$out[$field] = $value;
			}
		}

		return $out;
	}

	/**
	 * Add a row's counts (and pages) into its accumulator row.
	 *
	 * @param array<string, mixed> $into   The accumulator row.
	 * @param array<string, mixed> $row    The row.
	 * @param string[]             $counts The fields that add up.
	 *
	 * @return array<string, mixed> The accumulator row.
	 */
	private function mergeRow(array $into, array $row, array $counts): array {
		foreach ($counts as $count) {
			$into[$count] += $this->int(value: ($row[$count] ?? 0));
		}

		if (is_array($row['pages'] ?? null) === true && is_array($into['pages'] ?? null) === true) {
			$into['pages'] = array_values(array_unique(array_merge($into['pages'], $row['pages'])));
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
			$views = $this->int(value: $row['views'] ?? 0);
			$page['views'] += $views;
			$page['entrances'] += $this->int(value: $row['entrances'] ?? 0);
			$page['exits'] += $this->int(value: $row['exits'] ?? 0);
			$page['seconds'] += $this->float(value: $row['avgEngagementSeconds'] ?? 0) * $views;
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
				'avgEngagementSeconds' => $this->rate(numerator: (float)$page['seconds'], denominator: $views, decimals: 1),
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
			$goal['conversions'] += $this->int(value: $row['conversions'] ?? 0);
			$goal['completions'] += $this->int(value: $row['completions'] ?? 0);
			$goal['value'] = round($goal['value'] + $this->float(value: $row['value'] ?? 0), 2);
			$into[$id] = $goal;
		}

		return $into;
	}

	/**
	 * Merge experiment rows by id, variants by id
	 * (portal-traffic-experiments).
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's experiments.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addExperiments(array $into, mixed $rows): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || (string)($row['id'] ?? '') === '') {
				continue;
			}

			$id = (string)$row['id'];
			$experiment = $into[$id] ?? ['id' => $id, 'name' => $id, 'status' => 'running', 'variants' => []];
			$experiment['name'] = (string)($row['name'] ?? $experiment['name']);
			$experiment['status'] = (string)($row['status'] ?? $experiment['status']);
			foreach ((array)($row['variants'] ?? []) as $variant) {
				if (is_array($variant) === false || (string)($variant['id'] ?? '') === '') {
					continue;
				}

				$variantId = (string)$variant['id'];
				$summed = $experiment['variants'][$variantId]
					?? ['id' => $variantId, 'name' => (string)($variant['name'] ?? $variantId), 'sessions' => 0, 'conversions' => 0];
				$summed['sessions'] += $this->int(value: $variant['sessions'] ?? 0);
				$summed['conversions'] += $this->int(value: $variant['conversions'] ?? 0);
				$experiment['variants'][$variantId] = $summed;
			}

			$into[$id] = $experiment;
		}

		return $into;
	}

	/**
	 * The summed experiments with each variant's rate and the verdict
	 * re-derived from the summed counts.
	 *
	 * @param array<string, array<string, mixed>> $experiments The accumulator.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function finishExperiments(array $experiments): array {
		$out = [];
		foreach ($experiments as $experiment) {
			$variants = [];
			foreach ($experiment['variants'] as $variant) {
				$variant['rate'] = $this->rate(numerator: (float)$variant['conversions'], denominator: (int)$variant['sessions'], decimals: 3);
				$variants[] = $variant;
			}

			$experiment['variants'] = $variants;
			$out[] = $experiment + $this->experiments->verdict(variants: $variants);
		}

		return $out;
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
					'sessions' => (int)($funnel['steps'][$index]['sessions'] ?? 0) + $this->int(value: $step['sessions'] ?? 0),
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
				$form[$key] += $this->int(value: $row[$key] ?? 0);
			}

			foreach ((array)($row['fields'] ?? []) as $field) {
				if (is_array($field) === false || (string)($field['fieldId'] ?? '') === '') {
					continue;
				}

				$fieldId = (string)$field['fieldId'];
				$sum = $form['fields'][$fieldId] ?? ['fieldId' => $fieldId, 'ms' => 0, 'members' => 0, 'abandonedHere' => 0];
				$sum['ms'] += $this->int(value: $field['avgMs'] ?? 0);
				$sum['members'] += 1;
				$sum['abandonedHere'] += $this->int(value: $field['abandonedHere'] ?? 0);
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
					'avgMs' => (int)$this->rate(numerator: (float)$field['ms'], denominator: (int)$field['members'], decimals: 0),
					'abandonedHere' => $field['abandonedHere'],
				];
			}

			$starts = (int)$form['starts'];
			$out[] = [
				'formId' => $form['formId'],
				'starts' => $starts,
				'submits' => $form['submits'],
				'abandons' => $form['abandons'],
				'completionRate' => $this->rate(numerator: (float)$form['submits'], denominator: $starts, decimals: 3),
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
	 * A ratio rounded, or 0 when there is nothing to divide by.
	 *
	 * @param float $numerator   The top.
	 * @param int   $denominator The bottom.
	 * @param int   $decimals    The decimals kept.
	 *
	 * @return float The ratio.
	 */
	private function rate(float $numerator, int $denominator, int $decimals): float {
		if ($denominator <= 0) {
			return 0.0;
		}

		return round($numerator / $denominator, $decimals);
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
