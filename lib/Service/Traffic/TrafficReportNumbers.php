<?php

/**
 * Portaliq Traffic Report Numbers.
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
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Folds a period's daily records into the numbers a report or an alert
 * reads. Pure, and deliberately the same arithmetic the Traffic page's
 * `trafficSummary.js` does, so a figure in the mail is the figure on the
 * page.
 *
 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */
class TrafficReportNumbers {

	/**
	 * How many rows a ranked list keeps.
	 */
	private const TOP = 10;

	/**
	 * The totals and ranked lists of a set of daily records.
	 *
	 * @param array<int, array<string, mixed>> $records The `portalTrafficDaily` records of the period.
	 *
	 * @return array<string, mixed> pageViews, sessions, visitors, engagedSessions, bounceRate, days,
	 *                              pages, sources, devices, browsers, languages, regions, goals,
	 *                              conversionRate, funnels, forms, notFound, errors.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function fold(array $records): array {
		$sum = new TrafficRollupSum();
		$folded = $sum->sum(portal: '', date: '', members: [], records: $records, aggregatedAt: '');

		return $this->totals(folded: $folded, days: count($records))
			+ $this->breakdowns(folded: $folded)
			+ $this->outcomes(folded: $folded);
	}

	/**
	 * The period's totals.
	 *
	 * @param array<string, mixed> $folded The summed records.
	 * @param int                  $days   How many daily records were summed.
	 *
	 * @return array<string, mixed> days, pageViews, sessions, visitors, engagedSessions, bounceRate.
	 */
	private function totals(array $folded, int $days): array {
		return [
			'days' => $days,
			'pageViews' => (int)$folded['pageViews'],
			'sessions' => (int)$folded['sessions'],
			'visitors' => (int)$folded['visitors'],
			'engagedSessions' => (int)$folded['engagedSessions'],
			'bounceRate' => (float)$folded['bounceRate'],
		];
	}

	/**
	 * Where the visits went and came from, and on what.
	 *
	 * @param array<string, mixed> $folded The summed records.
	 *
	 * @return array<string, mixed> pages, sources, devices, browsers, languages, regions.
	 */
	private function breakdowns(array $folded): array {
		return [
			'pages' => array_slice((array)$folded['pages'], 0, self::TOP),
			'sources' => array_slice($this->sources(referrers: (array)$folded['referrers']), 0, self::TOP, true),
			'devices' => (array)$folded['devices'],
			'browsers' => (array)$folded['browsers'],
			'languages' => (array)$folded['languages'],
			'regions' => (array)$folded['regions'],
		];
	}

	/**
	 * What the visits achieved and where they went wrong.
	 *
	 * @param array<string, mixed> $folded The summed records.
	 *
	 * @return array<string, mixed> goals, conversionRate, funnels, forms, notFound, errors.
	 */
	private function outcomes(array $folded): array {
		return [
			'goals' => (array)$folded['goals'],
			'conversionRate' => (float)$folded['conversionRate'],
			'funnels' => (array)$folded['funnels'],
			'forms' => (array)$folded['forms'],
			'notFound' => array_slice((array)$folded['notFound'], 0, self::TOP),
			'errors' => array_slice((array)$folded['errors'], 0, self::TOP),
		];
	}

	/**
	 * The referrer counts per channel, a referrer without one counting as
	 * direct, largest first.
	 *
	 * @param array<int, array<string, mixed>> $referrers The summed referrer rows.
	 *
	 * @return array<string, int> The count per channel.
	 */
	private function sources(array $referrers): array {
		$sources = [];
		foreach ($referrers as $row) {
			$channel = (string)($row['channel'] ?? '');
			if ($channel === '') {
				$channel = 'direct';
			}

			$sources[$channel] = ($sources[$channel] ?? 0) + (int)($row['count'] ?? 0);
		}

		arsort($sources);
		return $sources;
	}

	/**
	 * One alert metric's value over a set of records.
	 *
	 * @param string                           $metric  `pageViews`, `sessions`, `visitors`, `notFound` or `goal:<id>`.
	 * @param array<int, array<string, mixed>> $records The period's records.
	 *
	 * @return float The value.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function metric(string $metric, array $records): float {
		$total = 0.0;
		foreach ($records as $record) {
			$total += $this->recordValue(metric: $metric, record: $record);
		}

		return $total;
	}

	/**
	 * One alert metric's value in one record.
	 *
	 * @param string               $metric The metric, see metric().
	 * @param array<string, mixed> $record The daily record.
	 *
	 * @return float The value.
	 */
	private function recordValue(string $metric, array $record): float {
		if (str_starts_with($metric, TrafficSegments::GOAL_PREFIX) === true) {
			return $this->conversions(goalId: substr($metric, strlen(TrafficSegments::GOAL_PREFIX)), record: $record);
		}

		if ($metric === 'notFound') {
			return $this->notFoundHits(record: $record);
		}

		return (float)($record[$metric] ?? 0);
	}

	/**
	 * One goal's conversions in one record.
	 *
	 * @param string               $goalId The goal id.
	 * @param array<string, mixed> $record The daily record.
	 *
	 * @return float The conversions.
	 */
	private function conversions(string $goalId, array $record): float {
		$total = 0.0;
		foreach ((array)($record['goals'] ?? []) as $goal) {
			if (is_array($goal) === true && (string)($goal['id'] ?? '') === $goalId) {
				$total += (float)($goal['conversions'] ?? 0);
			}
		}

		return $total;
	}

	/**
	 * The hits on missing pages in one record.
	 *
	 * @param array<string, mixed> $record The daily record.
	 *
	 * @return float The hits.
	 */
	private function notFoundHits(array $record): float {
		$total = 0.0;
		foreach ((array)($record['notFound'] ?? []) as $row) {
			$total += (float)(((array)$row)['hits'] ?? 0);
		}

		return $total;
	}

	/**
	 * The percent change from a previous value, or null when the previous
	 * value was zero and no change can be stated.
	 *
	 * @param float $current  The current value.
	 * @param float $previous The previous value.
	 *
	 * @return float|null The change in percent, one decimal.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function change(float $current, float $previous): ?float {
		if ($previous <= 0.0) {
			return null;
		}

		return round((($current - $previous) / $previous) * 100, 1);
	}
}
