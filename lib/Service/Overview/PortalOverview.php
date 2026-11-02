<?php

/**
 * Portaliq portal overview
 *
 * The figures of every published portal together, for the dashboard: traffic
 * over the last days and availability over the last full month.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Overview
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Overview;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Availability\AvailabilityReport;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficReportNumbers;

/**
 * Folds the figures of all published portals into one overview.
 *
 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
 */
class PortalOverview {
	/**
	 * How many pages the traffic overview names.
	 */
	public const TOP_PAGES = 4;

	/**
	 * Constructor.
	 *
	 * @param PortalResolver $portals The published portals.
	 * @param TrafficEventStore $traffic The daily traffic records.
	 * @param TrafficReportNumbers $numbers Folds daily records into totals.
	 * @param AvailabilityReport $availability The monthly availability per portal.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficEventStore $traffic,
		private readonly TrafficReportNumbers $numbers,
		private readonly AvailabilityReport $availability,
	) {
	}//end __construct()

	/**
	 * Traffic of every published portal over the last days, up to and
	 * including today. Visitors are added per portal, so a person who visited
	 * two portals counts twice.
	 *
	 * @param int $days How many days.
	 * @param DateTimeImmutable $now The moment asked for.
	 *
	 * @return array<string, mixed> `from`, `to`, `days`, `pageViews`, `sessions`, `visitors`,
	 *                              `engagedSessions`, `engagedShare` (percent, null without sessions)
	 *                              and `pages` (`path`, `portal`, `title`, `views`).
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function traffic(int $days, DateTimeImmutable $now): array {
		$today = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
		$from  = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
		$to    = $today->format('Y-m-d');

		$totals = ['pageViews' => 0, 'sessions' => 0, 'visitors' => 0, 'engagedSessions' => 0];
		$pages  = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$folded = $this->numbers->fold(records: $this->traffic->dailyBetween(portal: $slug, from: $from, to: $to));
			foreach (array_keys($totals) as $metric) {
				$totals[$metric] += (int)($folded[$metric] ?? 0);
			}

			foreach ((array)($folded['pages'] ?? []) as $page) {
				$pages[] = [
					'path' => (string)($page['path'] ?? ''),
					'portal' => $slug,
					'title' => (string)($portal['title'] ?? $slug),
					'views' => (int)($page['views'] ?? 0),
				];
			}
		}//end foreach

		usort($pages, static fn (array $one, array $two): int => $two['views'] <=> $one['views']);

		$share = null;
		if ($totals['sessions'] > 0) {
			$share = (int)round(($totals['engagedSessions'] / $totals['sessions']) * 100);
		}

		return [
			'from' => $from,
			'to' => $to,
			'days' => $days,
			'pageViews' => $totals['pageViews'],
			'sessions' => $totals['sessions'],
			'visitors' => $totals['visitors'],
			'engagedSessions' => $totals['engagedSessions'],
			'engagedShare' => $share,
			'pages' => array_slice($pages, 0, self::TOP_PAGES),
		];
	}//end traffic()

	/**
	 * Availability of every published portal over the last full month: each
	 * portal's percentage, all of them together, and the outages.
	 *
	 * @param DateTimeImmutable $now The moment asked for.
	 *
	 * @return array<string, mixed> `month`, `percentage` (null without checks), `outages`,
	 *                              `outageMinutes`, `lastOutage` and `portals` (`portal`, `title`,
	 *                              `percentage`).
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function availability(DateTimeImmutable $now): array {
		$month     = '';
		$intervals = 0;
		$available = 0;
		$outages   = [];
		$rows      = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$report = $this->availability->forPortal(portal: $slug, now: $now, months: 1);
			$last   = (array)($report['months'][0] ?? []);
			$month  = (string)($last['month'] ?? $month);
			$intervals += (int)($last['intervals'] ?? 0);
			$available += (int)($last['available'] ?? 0);
			$rows[] = [
				'portal' => $slug,
				'title' => (string)($portal['title'] ?? $slug),
				'percentage' => $last['percentage'] ?? null,
			];
			foreach ((array)($report['outages'] ?? []) as $outage) {
				$outages[] = ((array)$outage) + ['portal' => $slug, 'title' => (string)($portal['title'] ?? $slug)];
			}
		}//end foreach

		usort($outages, static fn (array $one, array $two): int => strcmp((string)$two['startedAt'], (string)$one['startedAt']));

		$percentage = null;
		if ($intervals > 0) {
			$percentage = round(($available / $intervals) * 100, 2);
		}

		return [
			'month' => $month,
			'percentage' => $percentage,
			'outages' => count($outages),
			'outageMinutes' => array_sum(array_map(static fn (array $outage): int => (int)($outage['durationMinutes'] ?? 0), $outages)),
			'lastOutage' => ($outages[0] ?? null),
			'portals' => $rows,
		];
	}//end availability()
}//end class
