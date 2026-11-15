<?php

/**
 * Portaliq availability report
 *
 * A portal's availability per month over the last full months, with its
 * outages, for a service level review.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Availability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Availability;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Kernel\Contracts\AvailabilityReportInterface;

/**
 * Availability is `available / intervals` as a percentage to two decimals.
 * No maintenance window is subtracted: no planned-maintenance record exists
 * in this app yet. A month with no intervals was not measured and says so
 * (null) rather than reading as 0 or 100.
 *
 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
class AvailabilityReport implements AvailabilityReportInterface {
	/**
	 * Constructor.
	 *
	 * @param AvailabilityStore $store The availability records.
	 */
	public function __construct(
		private readonly AvailabilityStore $store,
	) {
	}//end __construct()

	/**
	 * The report for one portal over the last full months before now.
	 *
	 * @param string $portal The portal slug.
	 * @param DateTimeImmutable $now The moment the report is asked for.
	 * @param int $months How many full months, 1 to 12.
	 *
	 * @return array{portal: string, from: string, until: string, months: array<int, array<string, mixed>>,
	 *     outages: array<int, array<string, mixed>>, summary: array<string, mixed>}
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function forPortal(string $portal, DateTimeImmutable $now, int $months = 12): array {
		$window  = self::window(now: $now, months: $months);
		$totals  = $this->monthTotals(portal: $portal, window: $window);
		$outages = $this->outages(portal: $portal, window: $window);

		return [
			'portal' => $portal,
			'from' => $window['from']->format('Y-m-d'),
			'until' => $window['until']->format('Y-m-d'),
			'months' => array_values($totals),
			'outages' => $outages,
			'summary' => $this->summary(totals: $totals, outages: $outages),
		];
	}//end forPortal()

	/**
	 * The full months before now, in UTC: the first day of this month, the first reported day and the last.
	 *
	 * @param DateTimeImmutable $now    The moment the report is asked for.
	 * @param int               $months How many full months, clamped to 1 to 12.
	 *
	 * @return array{first: DateTimeImmutable, from: DateTimeImmutable, until: DateTimeImmutable}
	 */
	private static function window(DateTimeImmutable $now, int $months): array {
		$months = max(1, min(12, $months));
		$first  = $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0);

		return ['first' => $first, 'from' => $first->modify('-' . $months . ' months'), 'until' => $first->modify('-1 day')];
	}//end window()

	/**
	 * Every month of the window with its stored days summed and its percentage, keyed `Y-m`.
	 *
	 * @param string                           $portal The portal slug.
	 * @param array<string, DateTimeImmutable> $window The report window.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function monthTotals(string $portal, array $window): array {
		$totals = self::emptyMonths(window: $window);
		$days   = $this->store->dailyBetween(portal: $portal, from: $window['from']->format('Y-m-d'), until: $window['until']->format('Y-m-d'));
		foreach ($days as $date => $day) {
			$key = substr((string)$date, 0, 7);
			if (isset($totals[$key]) === true) {
				$totals[$key] = self::addDay(total: $totals[$key], day: $day);
			}
		}

		foreach ($totals as $key => $total) {
			$totals[$key]['percentage'] = self::percentage(available: $total['available'], intervals: $total['intervals']);
		}

		return $totals;
	}//end monthTotals()

	/**
	 * Every month of the window with zero counts, keyed `Y-m`.
	 *
	 * @param array<string, DateTimeImmutable> $window The report window.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function emptyMonths(array $window): array {
		$totals = [];
		for ($month = $window['from']; $month < $window['first']; $month = $month->modify('+1 month')) {
			$key = $month->format('Y-m');
			$totals[$key] = ['month' => $key, 'intervals' => 0, 'available' => 0, 'degraded' => 0, 'down' => 0, 'noCheck' => 0];
		}

		return $totals;
	}//end emptyMonths()

	/**
	 * The available share in percent with two decimals, or null without intervals.
	 *
	 * @param int $available Intervals the portal answered.
	 * @param int $intervals Intervals measured.
	 *
	 * @return float|null
	 */
	private static function percentage(int $available, int $intervals): ?float {
		if ($intervals > 0) {
			return round(($available / $intervals) * 100, 2);
		}

		return null;
	}//end percentage()

	/**
	 * A month's totals with one stored day added.
	 *
	 * @param array<string, mixed> $total The month so far.
	 * @param array<string, mixed> $day   The stored day.
	 *
	 * @return array<string, mixed>
	 */
	private static function addDay(array $total, array $day): array {
		foreach (['intervals', 'available', 'degraded', 'down', 'noCheck'] as $counter) {
			$total[$counter] += (int)($day[$counter] ?? 0);
		}

		return $total;
	}//end addDay()

	/**
	 * The stored outages inside the window, each with its four fields.
	 *
	 * @param string                           $portal The portal slug.
	 * @param array<string, DateTimeImmutable> $window The report window.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function outages(string $portal, array $window): array {
		$outages    = [];
		$lastMoment = $window['until']->setTime(23, 59, 59)->format(DATE_ATOM);
		foreach ($this->store->outagesBetween(portal: $portal, from: $window['from']->format(DATE_ATOM), until: $lastMoment) as $outage) {
			$outages[] = [
				'startedAt' => (string)($outage['startedAt'] ?? ''),
				'endedAt' => (string)($outage['endedAt'] ?? ''),
				'durationMinutes' => (int)($outage['durationMinutes'] ?? 0),
				'cause' => (string)($outage['cause'] ?? ''),
			];
		}

		return $outages;
	}//end outages()

	/**
	 * The figures over the whole period: the availability of all months
	 * together, how many outages there were and how long they took, and the
	 * longest one.
	 *
	 * @param array<string, array<string, mixed>> $totals The months with their counters.
	 * @param array<int, array<string, mixed>> $outages The outages of the period.
	 *
	 * @return array<string, mixed> percentage (float or null), outageCount, outageMinutes, longestMinutes, longestStartedAt.
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	private function summary(array $totals, array $outages): array {
		$intervals = 0;
		$available = 0;
		foreach ($totals as $total) {
			$intervals += (int)$total['intervals'];
			$available += (int)$total['available'];
		}

		$minutes = 0;
		$longest = ['durationMinutes' => 0, 'startedAt' => ''];
		foreach ($outages as $outage) {
			$minutes += (int)$outage['durationMinutes'];
			if ((int)$outage['durationMinutes'] > (int)$longest['durationMinutes']) {
				$longest = $outage;
			}
		}

		return [
			'percentage' => self::percentage(available: $available, intervals: $intervals),
			'outageCount' => count($outages),
			'outageMinutes' => $minutes,
			'longestMinutes' => (int)$longest['durationMinutes'],
			'longestStartedAt' => (string)$longest['startedAt'],
		];
	}//end summary()

	/**
	 * The report as CSV: one line per month, then one line per outage.
	 *
	 * @param array<string, mixed> $report The report from forPortal().
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function csv(array $report): string {
		$lines = [['month', 'availability_percent', 'intervals', 'available', 'degraded', 'down', 'no_check']];
		foreach ($report['months'] as $month) {
			$percentage = '';
			if ($month['percentage'] !== null) {
				$percentage = number_format((float)$month['percentage'], 2, '.', '');
			}

			$lines[] = [$month['month'], $percentage, $month['intervals'], $month['available'], $month['degraded'], $month['down'], $month['noCheck']];
		}

		$lines[] = [];
		$lines[] = ['outage_started', 'outage_ended', 'duration_minutes', 'cause'];
		foreach ($report['outages'] as $outage) {
			$lines[] = [$outage['startedAt'], $outage['endedAt'], $outage['durationMinutes'], $outage['cause']];
		}

		$handle = fopen('php://temp', 'r+');
		foreach ($lines as $line) {
			fputcsv($handle, $line, ',', '"', '');
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		return $csv;
	}//end csv()
}//end class
