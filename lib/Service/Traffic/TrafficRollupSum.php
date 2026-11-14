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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
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
	private const COUNTERS = ['pageViews', 'sessions', 'visitors', 'engagedSessions', 'searchesWithoutCount'];

	/**
	 * The most referrers and outbound links a summed page row keeps, as
	 * many as one member's row carries (portal-page-traffic).
	 */
	private const PAGE_TOP = 10;

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
		'zeroResultSearches' => [['term'], ['count']],
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
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function sum(string $portal, string $date, array $members, array $records, string $aggregatedAt): array {
		$acc = $this->empty();
		foreach ($records as $record) {
			$acc = $this->addRecord(acc: $acc, record: $record);
		}

		$heading = [
			'portal' => $portal,
			'date' => $date,
			'segment' => '',
			'rollupOf' => array_values($members),
			'members' => count($records),
		];
		return $heading + $this->finish(acc: $acc) + ['aggregatedAt' => $aggregatedAt, 'lastEventAt' => $acc['last']];
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
		$acc = $this->addCounters(acc: $acc, record: $record);
		$acc = $this->addCollections(acc: $acc, record: $record);
		$acc = $this->addEngagement(acc: $acc, record: $record);
		return $this->addShapes(acc: $acc, record: $record);
	}

	/**
	 * Add a record's scalar counters, and its nullable ones when it carries them.
	 *
	 * @param array<string, mixed> $acc    The accumulator.
	 * @param array<string, mixed> $record The member's record.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addCounters(array $acc, array $record): array {
		foreach (self::COUNTERS as $key) {
			$acc[$key] += $this->values()->int(value: ($record[$key] ?? 0));
		}

		foreach (self::NULLABLE as $key) {
			if (is_numeric($record[$key] ?? null) === true) {
				$acc[$key] = ($acc[$key] ?? 0) + $this->values()->int(value: $record[$key]);
			}
		}

		return $acc;
	}

	/**
	 * Add a record's value-to-count maps and ranked lists.
	 *
	 * @param array<string, mixed> $acc    The accumulator.
	 * @param array<string, mixed> $record The member's record.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addCollections(array $acc, array $record): array {
		foreach (self::MAPS as $key) {
			$acc[$key] = $this->addMap(into: $acc[$key], from: ($record[$key] ?? []));
		}

		foreach (self::LISTS as $key => [$keys, $counts]) {
			$acc['lists'][$key] = $this->addList(into: $acc['lists'][$key], rows: ($record[$key] ?? []), keys: $keys, counts: $counts);
		}

		return $acc;
	}

	/**
	 * Add a record's engagement and conversion, weighted by its sessions,
	 * and keep the latest event time.
	 *
	 * @param array<string, mixed> $acc    The accumulator.
	 * @param array<string, mixed> $record The member's record.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addEngagement(array $acc, array $record): array {
		$sessions = $this->values()->int(value: ($record['sessions'] ?? 0));
		$acc['seconds'] += $this->values()->float(value: ($record['avgEngagementSeconds'] ?? 0)) * $sessions;
		$acc['converted'] += $this->values()->float(value: ($record['conversionRate'] ?? 0)) * $sessions;
		$acc['last'] = max($acc['last'], (string)($record['lastEventAt'] ?? ''));
		return $acc;
	}

	/**
	 * Add a record's pages, goals, funnels, forms, custom dimensions,
	 * experiments and e-mail figures.
	 *
	 * @param array<string, mixed> $acc    The accumulator.
	 * @param array<string, mixed> $record The member's record.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addShapes(array $acc, array $record): array {
		$acc['pages'] = $this->addPages(into: $acc['pages'], rows: ($record['pages'] ?? []));
		$acc['goals'] = $this->outcomes()->addGoals(into: $acc['goals'], rows: ($record['goals'] ?? []));
		$acc['funnels'] = $this->outcomes()->addFunnels(into: $acc['funnels'], rows: ($record['funnels'] ?? []));
		$acc['forms'] = $this->outcomes()->addForms(into: $acc['forms'], rows: ($record['forms'] ?? []));
		$acc['dimensions'] = $this->addDimensions(into: $acc['dimensions'], from: ($record['customDimensions'] ?? null));
		$acc['experiments'] = $this->outcomes()->addExperiments(into: $acc['experiments'], rows: ($record['experiments'] ?? []));
		$acc['emails']['opens'] += $this->values()->int(value: ($record['emails']['opens'] ?? 0));
		$acc['emails']['clicks'] += $this->values()->int(value: ($record['emails']['clicks'] ?? 0));

		return $acc;
	}

	/**
	 * The accumulator as the roll-up's record, up to the e-mail figures:
	 * sum() puts the heading before it and the times after it.
	 *
	 * @param array<string, mixed> $acc The accumulator.
	 *
	 * @return array<string, mixed> The summed `portalTrafficDaily` fields.
	 */
	private function finish(array $acc): array {
		// A roll-up carries no heatmap: its members' pages are on different
		// sites, and a click grid summed across sites draws nothing true.
		return $this->summedFields(acc: $acc)
			+ $this->rates(acc: $acc)
			+ $this->rankedShapes(acc: $acc)
			+ ['heatmaps' => [], 'emails' => $acc['emails']];
	}

	/**
	 * The summed counters, nullable counters and maps, as accumulated.
	 *
	 * @param array<string, mixed> $acc The accumulator.
	 *
	 * @return array<string, mixed> The fields, in declaration order.
	 */
	private function summedFields(array $acc): array {
		$out = [];
		foreach (array_merge(self::COUNTERS, self::NULLABLE, self::MAPS) as $key) {
			$out[$key] = $acc[$key];
		}

		return $out;
	}

	/**
	 * The mean engagement, bounce rate and conversion rate over the summed sessions.
	 *
	 * @param array<string, mixed> $acc The accumulator.
	 *
	 * @return array<string, float> avgEngagementSeconds, bounceRate, conversionRate.
	 */
	private function rates(array $acc): array {
		$sessions = (int)$acc['sessions'];
		return [
			'avgEngagementSeconds' => round($this->values()->ratio(numerator: (float)$acc['seconds'], denominator: $sessions), 1),
			'bounceRate' => round($this->values()->ratio(numerator: (float)($sessions - (int)$acc['engagedSessions']), denominator: $sessions), 3),
			'conversionRate' => round($this->values()->ratio(numerator: (float)$acc['converted'], denominator: $sessions), 3),
		];
	}

	/**
	 * The pages, ranked lists, goals, funnels, forms, custom dimensions and
	 * experiments, finished.
	 *
	 * @param array<string, mixed> $acc The accumulator.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function rankedShapes(array $acc): array {
		$out = ['pages' => $this->finishPages(pages: $acc['pages'])];
		foreach (self::LISTS as $key => [, $counts]) {
			$out[$key] = $this->ranked(rows: $acc['lists'][$key], countKey: $counts[0]);
		}

		$out['goals'] = array_values($acc['goals']);
		$out['funnels'] = $this->outcomes()->finishFunnels(funnels: $acc['funnels']);
		$out['forms'] = $this->outcomes()->finishForms(forms: $acc['forms']);
		$out['customDimensions'] = $acc['dimensions'];
		if ($acc['dimensions'] === []) {
			$out['customDimensions'] = null;
		}

		$out['experiments'] = $this->outcomes()->finishExperiments(experiments: $acc['experiments']);
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
			$into[(string)$value] = ($into[(string)$value] ?? 0) + $this->values()->int(value: $count);
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
			$into[$count] += $this->values()->int(value: ($row[$count] ?? 0));
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
	 * The per-page sessions, visitors, engaged sessions, referrers and
	 * outbound links (portal-page-traffic) add up like the views. A row
	 * written before they existed has none of them, and summing it as a
	 * zero would understate the page; the merged row then carries none of
	 * them either, so the page reads "not available" rather than a number
	 * that is only part of the truth.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param mixed                               $rows The record's pages.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 *
	 * @spec openspec/specs/portal-page-traffic/spec.md#requirement-each-daily-page-row-must-carry-its-sessions-visitors-sources-and-outbound-links
	 */
	private function addPages(array $into, mixed $rows): array {
		if (is_array($rows) === false) {
			return $into;
		}

		foreach ($rows as $row) {
			if (is_array($row) === false || trim((string)($row['path'] ?? '')) === '') {
				continue;
			}

			$into = $this->addPage(into: $into, row: $row);
		}

		return $into;
	}

	/**
	 * Add one page row with a path into the pages accumulator.
	 *
	 * @param array<string, array<string, mixed>> $into The accumulator.
	 * @param array<string, mixed>                $row  The record's page row.
	 *
	 * @return array<string, array<string, mixed>> The accumulator.
	 */
	private function addPage(array $into, array $row): array {
		$path = (string)$row['path'];
		$page = $into[$path] ?? $this->emptyPage(path: $path);
		$views = $this->values()->int(value: $row['views'] ?? 0);
		$page['views'] += $views;
		$page['entrances'] += $this->values()->int(value: $row['entrances'] ?? 0);
		$page['exits'] += $this->values()->int(value: $row['exits'] ?? 0);
		$page['seconds'] += $this->values()->float(value: $row['avgEngagementSeconds'] ?? 0) * $views;
		$into[$path] = $this->addPageDetail(page: $page, row: $row);
		return $into;
	}

	/**
	 * A page accumulator with nothing counted yet.
	 *
	 * @param string $path The page path.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function emptyPage(string $path): array {
		return [
			'path' => $path,
			'views' => 0,
			'entrances' => 0,
			'exits' => 0,
			'seconds' => 0.0,
			'detail' => true,
			'sessions' => 0,
			'visitors' => 0,
			'engagedSessions' => 0,
			'referrers' => [],
			'outbound' => [],
		];
	}

	/**
	 * Add a page row's per-page figures, or mark the page as lacking them.
	 *
	 * @param array<string, mixed> $page The page accumulator.
	 * @param array<string, mixed> $row  The record's page row.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addPageDetail(array $page, array $row): array {
		if (array_key_exists('sessions', $row) === false) {
			$page['detail'] = false;
			return $page;
		}

		foreach (['sessions', 'visitors', 'engagedSessions'] as $key) {
			$page[$key] += $this->values()->int(value: $row[$key] ?? 0);
		}

		$page['referrers'] = $this->addList(into: $page['referrers'], rows: ($row['referrers'] ?? []), keys: ['host', 'channel'], counts: ['count']);
		$page['outbound'] = $this->addList(into: $page['outbound'], rows: ($row['outbound'] ?? []), keys: ['url'], counts: ['count']);

		return $page;
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
			$row = [
				'path' => $page['path'],
				'views' => $views,
				'entrances' => $page['entrances'],
				'exits' => $page['exits'],
				'avgEngagementSeconds' => round($this->values()->ratio(numerator: (float)$page['seconds'], denominator: $views), 1),
			];
			if ($page['detail'] === true) {
				$row['sessions'] = $page['sessions'];
				$row['visitors'] = $page['visitors'];
				$row['engagedSessions'] = $page['engagedSessions'];
				$row['referrers'] = array_slice($this->ranked(rows: $page['referrers'], countKey: 'count'), 0, self::PAGE_TOP);
				$row['outbound'] = array_slice($this->ranked(rows: $page['outbound'], countKey: 'count'), 0, self::PAGE_TOP);
			}

			$out[] = $row;
		}

		usort($out, static fn (array $a, array $b): int => (int)$b['views'] <=> (int)$a['views']);

		return array_slice($out, 0, 100);
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
	 * The goal, experiment, funnel and form merging.
	 *
	 * @return TrafficRollupOutcomes The merger.
	 */
	private function outcomes(): TrafficRollupOutcomes {
		return new TrafficRollupOutcomes(experiments: $this->experiments);
	}

	/**
	 * The number readings the sum shares with its outcome merging.
	 *
	 * @return TrafficRollupValues The readings.
	 */
	private function values(): TrafficRollupValues {
		return new TrafficRollupValues();
	}
}
