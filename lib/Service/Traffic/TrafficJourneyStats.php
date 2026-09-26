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
	 * The most referrers and outbound links one page row carries
	 * (portal-page-traffic). A page's top ten sources answer "where did
	 * they come from"; the eleventh does not change the answer, and a
	 * hundred pages times a hundred rows would not fit a record.
	 */
	private const PAGE_TOP = 10;

	/**
	 * Constructor.
	 *
	 * @param TrafficPagePath $paths Keys a page view by its in-site route.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficPagePath $paths = new TrafficPagePath(),
	) {
	}

	/**
	 * Per-path views, entrances, exits and engagement, plus the transitions
	 * between consecutive page views in a session.
	 *
	 * Each page row also counts the sessions and visitors that viewed it,
	 * the engaged ones among them, the sources of the sessions that entered
	 * on it and the outbound links clicked on it (portal-page-traffic).
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param array<int|string, bool>          $engaged  Per session index, whether it was engaged.
	 *
	 * @return array{pages: array<int, array<string, mixed>>, transitions: array<int, array<string, mixed>>} Both lists, ranked.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-session-must-be-reconstructable-into-an-ordered-journey
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-each-daily-page-row-must-carry-its-sessions-visitors-sources-and-outbound-links
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-a-pages-traffic-must-be-counted-by-its-in-site-route
	 */
	public function pages(array $sessions, array $engaged = []): array {
		$pages = [];
		$transitions = [];
		foreach ($sessions as $key => $session) {
			$views = $this->pageViews(events: $session['events']);
			$seen = [];
			foreach ($views as $index => $view) {
				$path = $view['path'];
				$pages[$path] ??= $this->emptyPage(path: $path);
				$pages[$path]['views']++;
				$pages[$path]['entrances'] += (int)($index === 0);
				$pages[$path]['exits'] += (int)($index === count($views) - 1);
				if ($view['seconds'] !== null) {
					$pages[$path]['seconds'] += $view['seconds'];
					$pages[$path]['timed']++;
				}

				if (isset($seen[$path]) === false) {
					$seen[$path] = true;
					$pages[$path]['sessions']++;
					$pages[$path]['visitors'][(string)($session['visitor'] ?? '')] = true;
					$pages[$path]['engagedSessions'] += (int)(($engaged[$key] ?? false) === true);
				}

				if ($index > 0) {
					$edge = $views[$index - 1]['path'] . "\0" . $path;
					$transitions[$edge] = ($transitions[$edge] ?? 0) + 1;
				}
			}

			if ($views !== []) {
				$pages[$views[0]['path']] = $this->addReferrer(page: $pages[$views[0]['path']], first: ($session['events'][0] ?? []));
			}

			$pages = $this->addOutbound(pages: $pages, events: $session['events']);
		}

		return [
			'pages' => $this->rankPages(pages: $pages),
			'transitions' => $this->rankTransitions(transitions: $transitions),
		];
	}

	/**
	 * A page accumulator at zero.
	 *
	 * @param string $path The route.
	 *
	 * @return array<string, mixed> The counters.
	 */
	private function emptyPage(string $path): array {
		return [
			'path' => $path,
			'views' => 0,
			'entrances' => 0,
			'exits' => 0,
			'seconds' => 0.0,
			'timed' => 0,
			'sessions' => 0,
			'visitors' => [],
			'engagedSessions' => 0,
			'referrers' => [],
			'outbound' => [],
		];
	}

	/**
	 * Count an entering session's source on its landing page: the referrer
	 * host and channel of the session's first event, as the portal-wide
	 * `referrers` list reads them.
	 *
	 * @param array<string, mixed> $page  The landing page's accumulator.
	 * @param array<string, mixed> $first The session's first event.
	 *
	 * @return array<string, mixed> The accumulator.
	 */
	private function addReferrer(array $page, array $first): array {
		$host = trim((string)($first['referrerHost'] ?? ''));
		$channel = trim((string)($first['channel'] ?? ''));
		if ($host === '' && $channel === '') {
			return $page;
		}

		$id = $host . "\0" . $channel;
		$page['referrers'][$id] = ($page['referrers'][$id] ?? 0) + 1;

		return $page;
	}

	/**
	 * Count a session's outbound clicks on the page each was clicked on.
	 * A click on a page with no view in the day's rows gets a row of its
	 * own, with no views: the click still happened there.
	 *
	 * @param array<string, array<string, mixed>> $pages  The accumulators.
	 * @param array<int, array<string, mixed>>    $events The session's events.
	 *
	 * @return array<string, array<string, mixed>> The accumulators.
	 */
	private function addOutbound(array $pages, array $events): array {
		foreach ($events as $event) {
			if (($event['name'] ?? '') !== 'outbound_click') {
				continue;
			}

			$url = trim((string)($event['linkUrl'] ?? ($event['params']['link_url'] ?? '')));
			if ($url === '') {
				continue;
			}

			$path = $this->paths->ofEvent(event: $event);
			$pages[$path] ??= $this->emptyPage(path: $path);
			$pages[$path]['outbound'][$url] = ($pages[$path]['outbound'][$url] ?? 0) + 1;
		}

		return $pages;
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
	 * The in-site route of an event's page (portal-page-traffic): the
	 * `route` query parameter of its location, else its stored path, with
	 * no trailing slash.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The route.
	 */
	private function path(array $event): string {
		return $this->paths->ofEvent(event: $event);
	}

	/**
	 * Rank pages by views and finish the per-page mean, counts and lists.
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
				'sessions' => $page['sessions'],
				'visitors' => count($page['visitors']),
				'engagedSessions' => $page['engagedSessions'],
				'referrers' => $this->rankReferrers(counts: $page['referrers']),
				'outbound' => $this->rankOutbound(counts: $page['outbound']),
			];
		}

		usort($out, static fn (array $a, array $b): int => [$b['views'], $a['path']] <=> [$a['views'], $b['path']]);

		return array_slice($out, 0, self::TOP);
	}

	/**
	 * A page's referrers, ranked, top ten.
	 *
	 * @param array<string, int> $counts "host\0channel" => sessions.
	 *
	 * @return array<int, array{host: string, channel: string, count: int}> The rows.
	 */
	private function rankReferrers(array $counts): array {
		$out = [];
		foreach ($counts as $id => $count) {
			[$host, $channel] = explode("\0", (string)$id, 2);
			$out[] = ['host' => $host, 'channel' => $channel, 'count' => $count];
		}

		usort($out, static fn (array $a, array $b): int => [$b['count'], $a['host'], $a['channel']] <=> [$a['count'], $b['host'], $b['channel']]);

		return array_slice($out, 0, self::PAGE_TOP);
	}

	/**
	 * A page's outbound links, ranked, top ten.
	 *
	 * @param array<string, int> $counts Url => clicks.
	 *
	 * @return array<int, array{url: string, count: int}> The rows.
	 */
	private function rankOutbound(array $counts): array {
		$out = [];
		foreach ($counts as $url => $count) {
			$out[] = ['url' => (string)$url, 'count' => $count];
		}

		usort($out, static fn (array $a, array $b): int => [$b['count'], $a['url']] <=> [$a['count'], $b['url']]);

		return array_slice($out, 0, self::PAGE_TOP);
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
