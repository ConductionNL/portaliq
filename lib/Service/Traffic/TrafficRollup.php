<?php

/**
 * Portaliq Traffic Rollup.
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
 * One portal-day of sessions, counted the way the contract's section 3
 * lists it.
 *
 * Pure, like the sessioniser: sessions in, one `portalTrafficDaily` record
 * out, and the SAME sessions always give the SAME record. That property
 * is what makes recomputing a day idempotent, and the aggregation job
 * leans on it rather than on any bookkeeping of its own.
 *
 * Engagement follows the GA4 definition so the numbers mean what a
 * communications officer already expects: a session is engaged when it
 * lasted ten seconds or more, viewed more than one page, or scrolled.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */
class TrafficRollup {

	/**
	 * Seconds a session must last to count as engaged (GA4: 10).
	 */
	private const ENGAGED_SECONDS = 10;

	/**
	 * Constructor.
	 *
	 * @param TrafficJourneyStats    $journeys   Counts pages and transitions.
	 * @param TrafficDimensionCounts $dimensions Counts referrers, devices, searches and the like.
	 * @param TrafficGoals           $goals      Evaluates the portal's goals.
	 * @param TrafficFunnels         $funnels    Walks the portal's funnels.
	 * @param TrafficFormStats       $forms      Counts what happened to each form.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficJourneyStats $journeys = new TrafficJourneyStats(),
		private readonly TrafficDimensionCounts $dimensions = new TrafficDimensionCounts(),
		private readonly TrafficGoals $goals = new TrafficGoals(),
		private readonly TrafficFunnels $funnels = new TrafficFunnels(),
		private readonly TrafficFormStats $forms = new TrafficFormStats(),
	) {
	}

	/**
	 * Build the day's record.
	 *
	 * @param string                                    $portal       The portal slug.
	 * @param string                                    $date         The UTC day, YYYY-MM-DD.
	 * @param array<int, array<string, mixed>>          $sessions     The day's sessions, each `{visitor, explicit, events}`.
	 * @param string                                    $aggregatedAt When this record was computed, ISO 8601.
	 * @param array<string, mixed>                      $options      `persistClientId` and `accountLinking`, the portal's
	 *                                                                switches; `goals`, `funnels` and `customDimensions`,
	 *                                                                its resolved definitions.
	 *
	 * @return array<string, mixed> The `portalTrafficDaily` fields.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-visitors-must-be-counted-honestly-in-each-mode
	 * @spec openspec/changes/portal-traffic-outcomes/specs/portal-traffic-outcomes/spec.md#requirement-goals-must-be-evaluated-from-the-portals-own-definitions
	 */
	public function build(string $portal, string $date, array $sessions, string $aggregatedAt, array $options = []): array {
		$totals = $this->totals(sessions: $sessions);
		$pages = $this->journeys->pages(sessions: $sessions);
		// NULL, NOT ZERO, in cookieless mode (Ruben, decision 2). A hash
		// that does not survive the day cannot say whether it was here
		// yesterday, and a zero would read as "nobody came back". The same
		// for accounts on a portal that does not link them.
		$newVisitors = null;
		$returningVisitors = null;
		$accounts = null;
		if (($options['persistClientId'] ?? false) === true) {
			$newVisitors = $totals['newVisitors'];
			$returningVisitors = $totals['returningVisitors'];
		}

		if (($options['accountLinking'] ?? false) === true) {
			$accounts = $totals['accounts'];
		}

		$goalDefinitions = $this->definitions(options: $options, key: 'goals');

		return [
			'portal' => $portal,
			'date' => $date,
			'pageViews' => $totals['pageViews'],
			'sessions' => count($sessions),
			'visitors' => $totals['visitors'],
			'newVisitors' => $newVisitors,
			'returningVisitors' => $returningVisitors,
			'accounts' => $accounts,
			'engagedSessions' => $totals['engaged'],
			'avgEngagementSeconds' => $this->mean(sum: $totals['seconds'], count: count($sessions)),
			'bounceRate' => $this->bounceRate(engaged: $totals['engaged'], sessions: count($sessions)),
			'events' => $totals['events'],
			'pages' => $pages['pages'],
			'transitions' => $pages['transitions'],
			'referrers' => $this->dimensions->perSession(
				sessions: $sessions,
				keys: ['referrerHost', 'channel'],
				names: ['host', 'channel'],
				countKey: 'count'
			),
			'campaigns' => $this->dimensions->perSession(
				sessions: $sessions,
				keys: ['campaign', 'source', 'medium'],
				names: ['campaign', 'source', 'medium'],
				countKey: 'sessions'
			),
			'devices' => $this->dimensions->perSessionMap(sessions: $sessions, key: 'deviceType'),
			'browsers' => $this->dimensions->perSessionMap(sessions: $sessions, key: 'browser'),
			'os' => $this->dimensions->perSessionMap(sessions: $sessions, key: 'os'),
			'languages' => $this->dimensions->perSessionMap(sessions: $sessions, key: 'language'),
			'regions' => $this->dimensions->perSessionMap(sessions: $sessions, key: 'region'),
			'searches' => $this->dimensions->perEvent(sessions: $sessions, name: 'search', keys: ['searchTerm', 'params.search_term'], label: 'term'),
			'downloads' => $this->dimensions->perEvent(
				sessions: $sessions,
				name: 'file_download',
				keys: ['fileName', 'linkUrl', 'params.file_name'],
				label: 'file'
			),
			'outbound' => $this->dimensions->perEvent(sessions: $sessions, name: 'outbound_click', keys: ['linkUrl', 'params.link_url'], label: 'url'),
			'goals' => $this->goals->rows(goals: $goalDefinitions, sessions: $sessions),
			'conversionRate' => $this->goals->conversionRate(goals: $goalDefinitions, sessions: $sessions),
			'funnels' => $this->funnels->rows(funnels: $this->definitions(options: $options, key: 'funnels'), sessions: $sessions),
			'forms' => $this->forms->rows(sessions: $sessions),
			'notFound' => $this->dimensions->perEvent(
				sessions: $sessions,
				name: 'page_not_found',
				keys: ['pagePath', 'pageLocation'],
				label: 'path',
				countKey: 'hits'
			),
			// Null, not {}, when nothing was carried: the object store
			// refuses an empty object for a typed property and clears it
			// on null.
			'customDimensions' => $this->customDimensions(sessions: $sessions, options: $options),
			'emails' => [
				'opens' => (int)($totals['events']['email_open'] ?? 0),
				'clicks' => (int)($totals['events']['email_click'] ?? 0),
			],
			'aggregatedAt' => $aggregatedAt,
			'lastEventAt' => $totals['lastEventAt'],
		];
	}

	/**
	 * The custom dimension counts, or null when no declared dimension
	 * carried a value.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 * @param array<string, mixed>             $options  The options.
	 *
	 * @return array<string, array<string, int>>|null The counts.
	 */
	private function customDimensions(array $sessions, array $options): ?array {
		$counts = $this->dimensions->custom(
			sessions: $sessions,
			definitions: $this->definitions(options: $options, key: 'customDimensions')
		);
		if ($counts === []) {
			return null;
		}

		return $counts;
	}

	/**
	 * A list of definitions from the options, or [] when none were given.
	 *
	 * @param array<string, mixed> $options The options.
	 * @param string               $key     `goals`, `funnels` or `customDimensions`.
	 *
	 * @return array<int, array<string, mixed>> The definitions.
	 */
	private function definitions(array $options, string $key): array {
		$value = $options[$key] ?? [];
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn ($row): bool => is_array($row)));
	}

	/**
	 * The session-level totals in one pass.
	 *
	 * @param array<int, array<string, mixed>> $sessions The sessions.
	 *
	 * @return array<string, mixed> pageViews, visitors, newVisitors, returningVisitors, accounts, engaged, seconds, events, lastEventAt.
	 */
	private function totals(array $sessions): array {
		$visitors = [];
		$types = [];
		$accounts = [];
		$events = [];
		$pageViews = 0;
		$engaged = 0;
		$seconds = 0.0;
		$last = '';
		foreach ($sessions as $session) {
			$visitors[$session['visitor']] = true;
			$type = $this->visitorType(session: $session);
			if ($type !== '' && ($types[$session['visitor']] ?? '') !== 'new') {
				$types[$session['visitor']] = $type;
			}

			$views = 0;
			$scrolled = false;
			foreach ($session['events'] as $event) {
				$name = (string)($event['name'] ?? '');
				$events[$name] = ($events[$name] ?? 0) + 1;
				$views += (int)($name === 'page_view');
				$scrolled = $scrolled || ($name === 'scroll');
				$last = max($last, (string)($event['occurredAt'] ?? ''));
				$userRef = trim((string)($event['userRef'] ?? ''));
				if ($userRef !== '') {
					$accounts[$userRef] = true;
				}
			}

			$duration = $this->duration(session: $session);
			$seconds += $duration;
			$pageViews += $views;
			$engaged += (int)($views > 1 || $scrolled === true || $duration >= self::ENGAGED_SECONDS);
		}

		ksort($events);

		return [
			'pageViews' => $pageViews,
			'visitors' => count($visitors),
			'newVisitors' => count(array_filter($types, static fn (string $t): bool => $t === 'new')),
			'returningVisitors' => count(array_filter($types, static fn (string $t): bool => $t === 'returning')),
			'accounts' => count($accounts),
			'engaged' => $engaged,
			'seconds' => $seconds,
			'events' => $events,
			'lastEventAt' => $last,
		];
	}

	/**
	 * Whether a session's visitor said it was new or returning, or nothing.
	 *
	 * Only a visitor with a persisted client id can say: the client sends
	 * `visitorType` on `session_start` (or, from the phase 0 client,
	 * `first: true`) right after creating or finding the id. A cookieless
	 * visitor is a hash that does not survive the day, so it says nothing,
	 * and the rollup reports both counts as not available rather than
	 * calling every one of them new.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return string `new`, `returning`, or '' when unknown.
	 */
	private function visitorType(array $session): string {
		if (str_starts_with((string)$session['visitor'], 'c:') === false) {
			return '';
		}

		foreach ($session['events'] as $event) {
			if (($event['name'] ?? '') !== 'session_start') {
				continue;
			}

			$type = (string)($event['params']['visitorType'] ?? '');
			if ($type === 'new' || (($event['params']['first'] ?? false) === true)) {
				return 'new';
			}

			if ($type === 'returning') {
				return 'returning';
			}
		}

		return '';
	}

	/**
	 * Seconds between a session's first and last event.
	 *
	 * @param array<string, mixed> $session The session.
	 *
	 * @return float The duration.
	 */
	private function duration(array $session): float {
		$events = $session['events'];
		if (count($events) < 2) {
			return 0.0;
		}

		$first = (float)($events[0]['_at'] ?? 0);
		$last = (float)($events[count($events) - 1]['_at'] ?? 0);

		return max(0.0, $last - $first);
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

	/**
	 * The share of sessions that were not engaged, 0 with no sessions.
	 *
	 * @param int $engaged  Engaged sessions.
	 * @param int $sessions All sessions.
	 *
	 * @return float Between 0 and 1, three decimals.
	 */
	private function bounceRate(int $engaged, int $sessions): float {
		if ($sessions <= 0) {
			return 0.0;
		}

		return round(($sessions - $engaged) / $sessions, 3);
	}
}
