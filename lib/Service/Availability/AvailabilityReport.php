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
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Availability;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Availability is `available / intervals` as a percentage to two decimals.
 * No maintenance window is subtracted: no planned-maintenance record exists
 * in this app yet. A month with no intervals was not measured and says so
 * (null) rather than reading as 0 or 100.
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
 */
class AvailabilityReport {
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
	 * @return array{portal: string, from: string, until: string, months: array<int, array<string, mixed>>, outages: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function forPortal(string $portal, DateTimeImmutable $now, int $months = 12): array {
		$months = max(1, min(12, $months));
		$firstOfThisMonth = $now->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->setTime(0, 0);
		$from = $firstOfThisMonth->modify('-' . $months . ' months');
		$until = $firstOfThisMonth->modify('-1 day');

		$totals = [];
		for ($month = $from; $month < $firstOfThisMonth; $month = $month->modify('+1 month')) {
			$totals[$month->format('Y-m')] = ['month' => $month->format('Y-m'), 'intervals' => 0, 'available' => 0, 'degraded' => 0, 'down' => 0, 'noCheck' => 0];
		}

		foreach ($this->store->dailyBetween(portal: $portal, from: $from->format('Y-m-d'), until: $until->format('Y-m-d')) as $date => $day) {
			$key = substr((string)$date, 0, 7);
			if (isset($totals[$key]) === false) {
				continue;
			}

			foreach (['intervals', 'available', 'degraded', 'down', 'noCheck'] as $counter) {
				$totals[$key][$counter] += (int)($day[$counter] ?? 0);
			}
		}

		foreach ($totals as $key => $total) {
			$totals[$key]['percentage'] = null;
			if ($total['intervals'] > 0) {
				$totals[$key]['percentage'] = round(($total['available'] / $total['intervals']) * 100, 2);
			}
		}

		$outages = [];
		foreach ($this->store->outagesBetween(portal: $portal, from: $from->format(DATE_ATOM), until: $until->setTime(23, 59, 59)->format(DATE_ATOM)) as $outage) {
			$outages[] = [
				'startedAt' => (string)($outage['startedAt'] ?? ''),
				'endedAt' => (string)($outage['endedAt'] ?? ''),
				'durationMinutes' => (int)($outage['durationMinutes'] ?? 0),
				'cause' => (string)($outage['cause'] ?? ''),
			];
		}

		return [
			'portal' => $portal,
			'from' => $from->format('Y-m-d'),
			'until' => $until->format('Y-m-d'),
			'months' => array_values($totals),
			'outages' => $outages,
		];
	}//end forPortal()

	/**
	 * The report as CSV: one line per month, then one line per outage.
	 *
	 * @param array<string, mixed> $report The report from forPortal().
	 *
	 * @return string
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
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
