<?php

/**
 * Portaliq Traffic Report Periods.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar arithmetic of reports and alerts, in UTC, pure.
 *
 * A period is `{key, from, to, previousFrom, previousTo}`: the key names
 * the period once (a report is sent once per key, an alert fires once per
 * key), `from`..`to` are its inclusive days, and the previous period is
 * the one of the same length right before it.
 *
 * A report always covers the LAST COMPLETE period: yesterday, the ISO
 * week that ended on the most recent Sunday, the previous calendar month.
 * An `above` alert watches the CURRENT period so it can fire the hour a
 * threshold is crossed; `below` and `changeAbove` need the period to be
 * over before they can say anything, so they watch the last complete one.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
 */
class TrafficReportPeriods {

	/**
	 * The period a report of this cadence covers now.
	 *
	 * @param string            $cadence `daily`, `weekly` or `monthly`.
	 * @param DateTimeImmutable $now     The clock.
	 *
	 * @return array{key: string, from: string, to: string, previousFrom: string, previousTo: string} The period.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function reportPeriod(string $cadence, DateTimeImmutable $now): array {
		$today = $this->day(now: $now);

		return match ($cadence) {
			'weekly' => $this->lastWeek(today: $today),
			'monthly' => $this->lastMonth(today: $today),
			default => $this->days(from: $today->modify('-1 day'), to: $today->modify('-1 day'), key: $today->modify('-1 day')->format('Y-m-d')),
		};
	}

	/**
	 * The period an alert watches now.
	 *
	 * @param string            $period     `day` or `week`.
	 * @param string            $comparison `above`, `below` or `changeAbove`.
	 * @param DateTimeImmutable $now        The clock.
	 *
	 * @return array{key: string, from: string, to: string, previousFrom: string, previousTo: string} The period.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-an-alert-must-fire-once-per-period
	 */
	public function alertPeriod(string $period, string $comparison, DateTimeImmutable $now): array {
		$today = $this->day(now: $now);
		if ($comparison !== 'above') {
			return ($period === 'week') ? $this->lastWeek(today: $today) : $this->reportPeriod(cadence: 'daily', now: $now);
		}

		if ($period === 'week') {
			$monday = $today->modify('monday this week');

			return $this->days(from: $monday, to: $today, key: $monday->format('o-\WW'));
		}

		return $this->days(from: $today, to: $today, key: $today->format('Y-m-d'));
	}

	/**
	 * The ISO week that ended on the most recent Sunday.
	 *
	 * @param DateTimeImmutable $today Today, UTC midnight.
	 *
	 * @return array{key: string, from: string, to: string, previousFrom: string, previousTo: string} The period.
	 */
	private function lastWeek(DateTimeImmutable $today): array {
		$monday = $today->modify('monday this week')->modify('-7 days');

		return $this->days(from: $monday, to: $monday->modify('+6 days'), key: $monday->format('o-\WW'));
	}

	/**
	 * The previous calendar month, with the month before it as previous.
	 *
	 * @param DateTimeImmutable $today Today, UTC midnight.
	 *
	 * @return array{key: string, from: string, to: string, previousFrom: string, previousTo: string} The period.
	 */
	private function lastMonth(DateTimeImmutable $today): array {
		$first = $today->modify('first day of last month');
		$previousFirst = $first->modify('first day of last month');

		return [
			'key' => $first->format('Y-m'),
			'from' => $first->format('Y-m-d'),
			'to' => $first->modify('last day of this month')->format('Y-m-d'),
			'previousFrom' => $previousFirst->format('Y-m-d'),
			'previousTo' => $previousFirst->modify('last day of this month')->format('Y-m-d'),
		];
	}

	/**
	 * A span of days and the same span right before it.
	 *
	 * @param DateTimeImmutable $from The first day.
	 * @param DateTimeImmutable $to   The last day.
	 * @param string            $key  The period's key.
	 *
	 * @return array{key: string, from: string, to: string, previousFrom: string, previousTo: string} The period.
	 */
	private function days(DateTimeImmutable $from, DateTimeImmutable $to, string $key): array {
		$length = (int)$from->diff($to)->days + 1;

		return [
			'key' => $key,
			'from' => $from->format('Y-m-d'),
			'to' => $to->format('Y-m-d'),
			'previousFrom' => $from->modify('-' . $length . ' days')->format('Y-m-d'),
			'previousTo' => $from->modify('-1 day')->format('Y-m-d'),
		];
	}

	/**
	 * The clock as a UTC midnight.
	 *
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return DateTimeImmutable Today at 00:00 UTC.
	 */
	private function day(DateTimeImmutable $now): DateTimeImmutable {
		return $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
	}
}
