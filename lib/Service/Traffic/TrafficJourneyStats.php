<?php

/**
 * Portaliq Traffic Journey Stats.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Pages and the transitions between them, per session: views, entrances,
 * exits, time on page, and how often visitors moved from one path to the
 * next. Pure, like the rollup it serves.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
 */
class TrafficJourneyStats {

	/**
	 * The most entries a ranked list carries. A page that is not in the top
	 * hundred is not one anybody will read a report for, and a record that
	 * grows with the site's page count is a record that stops fitting.
	 */
	private const TOP = 100;

	/**
	 * Per-path views, entrances, exits and engagement, plus the transitions
	 * between consecutive page views in a session.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array{pages: array<int, array<string, mixed>>, transitions: array<int, array<string, mixed>>} Both lists, ranked.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
	 */
	public function pages(array $sessions): array {
		$pages = [];
		$transitions = [];
		foreach ($sessions as $session) {
			$views = $this->pageViews(events: $session['events']);
			foreach ($views as $index => $view) {
				$path = $view['path'];
				$pages[$path] ??= ['path' => $path, 'views' => 0, 'entrances' => 0, 'exits' => 0, 'seconds' => 0.0, 'timed' => 0];
				$pages[$path]['views']++;
				$pages[$path]['entrances'] += (int)($index === 0);
				$pages[$path]['exits'] += (int)($index === count($views) - 1);
				if ($view['seconds'] !== null) {
					$pages[$path]['seconds'] += $view['seconds'];
					$pages[$path]['timed']++;
				}

				if ($index > 0) {
					$edge = $views[$index - 1]['path'] . "\0" . $path;
					$transitions[$edge] = ($transitions[$edge] ?? 0) + 1;
				}
			}
		}

		return [
			'pages' => $this->rankPages(pages: $pages),
			'transitions' => $this->rankTransitions(transitions: $transitions),
		];
	}

	/**
	 * A session's page views in order, each with the seconds until the
	 * next event in the session (null for the last one, which has no
	 * "next": a single-page visit has no measurable time on page).
	 *
	 * @param array<int, array<string, mixed>> $events The session's ordered events.
	 *
	 * @return array<int, array{path: string, seconds: float|null}> The views.
	 */
	private function pageViews(array $events): array {
		$views = [];
		$count = count($events);
		foreach ($events as $index => $event) {
			if (($event['name'] ?? '') !== 'page_view') {
				continue;
			}

			$seconds = null;
			if ($index < $count - 1) {
				$seconds = max(0.0, (float)($events[$count - 1]['_at'] ?? 0) - (float)($event['_at'] ?? 0));
				$next = $this->nextPageView(events: $events, from: $index + 1);
				if ($next !== null) {
					$seconds = max(0.0, (float)($events[$next]['_at'] ?? 0) - (float)($event['_at'] ?? 0));
				}
			}

			$views[] = ['path' => $this->path(event: $event), 'seconds' => $seconds];
		}

		return $views;
	}

	/**
	 * The index of the next page view at or after `$from`, or null.
	 *
	 * @param array<int, array<string, mixed>> $events The events.
	 * @param int                              $from   Where to start looking.
	 *
	 * @return int|null The index.
	 */
	private function nextPageView(array $events, int $from): ?int {
		$count = count($events);
		for ($i = $from; $i < $count; $i++) {
			if (($events[$i]['name'] ?? '') === 'page_view') {
				return $i;
			}
		}

		return null;
	}

	/**
	 * The path of an event's page: the stored path, else the path of its
	 * location, else "/".
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The path.
	 */
	private function path(array $event): string {
		$path = trim((string)($event['pagePath'] ?? ''));
		if ($path !== '') {
			return $path;
		}

		$fromLocation = parse_url((string)($event['pageLocation'] ?? ''), PHP_URL_PATH);
		if (is_string($fromLocation) === true && $fromLocation !== '') {
			return $fromLocation;
		}

		return '/';
	}

	/**
	 * Rank pages by views and finish the per-page mean.
	 *
	 * @param array<string, array<string, mixed>> $pages Path => counters.
	 *
	 * @return array<int, array<string, mixed>> The top pages.
	 */
	private function rankPages(array $pages): array {
		$out = [];
		foreach ($pages as $page) {
			$out[] = [
				'path' => $page['path'],
				'views' => $page['views'],
				'entrances' => $page['entrances'],
				'exits' => $page['exits'],
				'avgEngagementSeconds' => $this->mean(sum: $page['seconds'], count: $page['timed']),
			];
		}

		usort($out, static fn (array $a, array $b): int => [$b['views'], $a['path']] <=> [$a['views'], $b['path']]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * Rank transitions by count.
	 *
	 * @param array<string, int> $transitions "from\0to" => count.
	 *
	 * @return array<int, array{from: string, to: string, count: int}> The top transitions.
	 */
	private function rankTransitions(array $transitions): array {
		$out = [];
		foreach ($transitions as $edge => $count) {
			[$from, $to] = explode("\0", $edge, 2);
			$out[] = ['from' => $from, 'to' => $to, 'count' => $count];
		}

		usort($out, static fn (array $a, array $b): int => [$b['count'], $a['from'], $a['to']] <=> [$a['count'], $b['from'], $b['to']]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * A mean rounded to one decimal, 0 for an empty set.
	 *
	 * @param float $sum   The sum.
	 * @param int   $count The count.
	 *
	 * @return float The mean.
	 */
	private function mean(float $sum, int $count): float {
		if ($count <= 0) {
			return 0.0;
		}

		return round($sum / $count, 1);
	}
}
