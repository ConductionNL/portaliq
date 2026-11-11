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
use OCA\Portaliq\Service\PublishedPortals;
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
	 * @param PublishedPortals $portals The published portals.
	 * @param TrafficEventStore $traffic The daily traffic records.
	 * @param TrafficReportNumbers $numbers Folds daily records into totals.
	 * @param AvailabilityReport $availability The monthly availability per portal.
	 */
	public function __construct(
		private readonly PublishedPortals $portals,
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

		$folds = [];
		foreach ($this->published() as $owner) {
			$folds[] = [$owner, $this->numbers->fold(records: $this->traffic->dailyBetween(portal: $owner['portal'], from: $from, to: $to))];
		}

		$totals = $this->trafficTotals(folds: $folds);

		return ['from' => $from, 'to' => $to, 'days' => $days] + $totals + [
			'engagedShare' => $this->engagedShare(totals: $totals),
			'pages' => array_slice($this->rankedPages(folds: $folds), 0, self::TOP_PAGES),
		];
	}//end traffic()

	/**
	 * Every published portal with a slug, as its `portal` and `title`; a
	 * portal without a title is named by its slug.
	 *
	 * @return array<int, array{portal: string, title: string}>
	 */
	private function published(): array {
		$owners = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug !== '') {
				$owners[] = ['portal' => $slug, 'title' => (string)($portal['title'] ?? $slug)];
			}
		}

		return $owners;
	}//end published()

	/**
	 * The page views, sessions, visitors and engaged sessions of every portal, added.
	 *
	 * @param array<int, array{0: array<string, string>, 1: array<string, mixed>}> $folds Each portal and its folded traffic.
	 *
	 * @return array{pageViews: int, sessions: int, visitors: int, engagedSessions: int}
	 */
	private function trafficTotals(array $folds): array {
		$totals = ['pageViews' => 0, 'sessions' => 0, 'visitors' => 0, 'engagedSessions' => 0];
		foreach ($folds as [, $folded]) {
			foreach (array_keys($totals) as $metric) {
				$totals[$metric] += (int)($folded[$metric] ?? 0);
			}
		}

		return $totals;
	}//end trafficTotals()

	/**
	 * The engaged share of the sessions in percent, or null without sessions.
	 *
	 * @param array{sessions: int, engagedSessions: int} $totals The added traffic.
	 *
	 * @return int|null
	 */
	private function engagedShare(array $totals): ?int {
		if ($totals['sessions'] <= 0) {
			return null;
		}

		return (int)round(($totals['engagedSessions'] / $totals['sessions']) * 100);
	}//end engagedShare()

	/**
	 * Every page of every portal, most viewed first; equal views keep their order.
	 *
	 * @param array<int, array{0: array<string, string>, 1: array<string, mixed>}> $folds Each portal and its folded traffic.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rankedPages(array $folds): array {
		$pages = [];
		foreach ($folds as [$owner, $folded]) {
			foreach ((array)($folded['pages'] ?? []) as $page) {
				$pages[] = ['path' => (string)($page['path'] ?? '')] + $owner + ['views' => (int)($page['views'] ?? 0)];
			}
		}

		usort($pages, static fn (array $one, array $two): int => $two['views'] <=> $one['views']);

		return $pages;
	}//end rankedPages()

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
		$reports = $this->lastMonthReports(now: $now);
		$outages = $this->newestOutagesFirst(reports: $reports);

		return [
			'month' => $this->lastMonth(reports: $reports),
			'percentage' => $this->percentage(reports: $reports),
			'outages' => count($outages),
			'outageMinutes' => array_sum(array_map(static fn (array $outage): int => (int)($outage['durationMinutes'] ?? 0), $outages)),
			'lastOutage' => ($outages[0] ?? null),
			'portals' => array_map(static fn (array $report): array => $report[0] + ['percentage' => $report[1]['percentage'] ?? null], $reports),
		];
	}//end availability()

	/**
	 * Every published portal with its last full month and its outages.
	 *
	 * @param DateTimeImmutable $now The moment asked for.
	 *
	 * @return array<int, array{0: array<string, string>, 1: array<string, mixed>, 2: array<mixed>}>
	 */
	private function lastMonthReports(DateTimeImmutable $now): array {
		$reports = [];
		foreach ($this->published() as $owner) {
			$report    = $this->availability->forPortal(portal: $owner['portal'], now: $now, months: 1);
			$reports[] = [$owner, (array)($report['months'][0] ?? []), (array)($report['outages'] ?? [])];
		}

		return $reports;
	}//end lastMonthReports()

	/**
	 * The month of the last portal that reports one, or ''.
	 *
	 * @param array<int, array{0: array<string, string>, 1: array<string, mixed>, 2: array<mixed>}> $reports Each portal, its last month and its outages.
	 *
	 * @return string
	 */
	private function lastMonth(array $reports): string {
		$month = '';
		foreach ($reports as [, $last]) {
			$month = (string)($last['month'] ?? $month);
		}

		return $month;
	}//end lastMonth()

	/**
	 * The share of available checks of all portals together, or null without checks.
	 *
	 * @param array<int, array{0: array<string, string>, 1: array<string, mixed>, 2: array<mixed>}> $reports Each portal, its last month and its outages.
	 *
	 * @return float|null
	 */
	private function percentage(array $reports): ?float {
		$intervals = 0;
		$available = 0;
		foreach ($reports as [, $last]) {
			$intervals += (int)($last['intervals'] ?? 0);
			$available += (int)($last['available'] ?? 0);
		}

		if ($intervals <= 0) {
			return null;
		}

		return round(($available / $intervals) * 100, 2);
	}//end percentage()

	/**
	 * Every portal's outages, each with its portal and title, the newest first.
	 *
	 * @param array<int, array{0: array<string, string>, 1: array<string, mixed>, 2: array<mixed>}> $reports Each portal, its last month and its outages.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function newestOutagesFirst(array $reports): array {
		$outages = [];
		foreach ($reports as [$owner, , $listed]) {
			foreach ($listed as $outage) {
				$outages[] = ((array)$outage) + $owner;
			}
		}

		usort($outages, static fn (array $one, array $two): int => strcmp((string)$two['startedAt'], (string)$one['startedAt']));

		return $outages;
	}//end newestOutagesFirst()
}//end class
