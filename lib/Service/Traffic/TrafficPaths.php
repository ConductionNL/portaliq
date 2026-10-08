<?php

/**
 * Portaliq Traffic Paths.
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
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The path explorer's arithmetic: a visit's path, the fold of many visits
 * into weighted paths, and the steps, nodes, drop-offs and links the
 * Traffic page draws. Pure: no storage, no clock.
 *
 * EXACT PATHS, NOT CHAINED PAIRS. Every count here is a count of visits
 * whose OWN sequence of page views matches. The daily rollup's
 * `transitions[]` cannot give this: visits from A to B and visits from B
 * to C may be different visits, so chaining the pairs draws paths nobody
 * walked.
 *
 * A PATH, and the three rules that make one:
 * - only `page_view` events are steps (other events are read by the
 *   caller, because they keep a visit alive, but never drawn);
 * - a step is the page's in-site ROUTE (`TrafficPagePath`, the same key
 *   the daily figures and a page's detail cards use), not the stored
 *   path: the built-in site keeps its route in `?route=` and the stored
 *   path of every one of its pages is the renderer's;
 * - two views of the same route in a row are one step, so a reload does
 *   not read as "went from /news to /news";
 * - the order is the sessioniser's, which this class receives already
 *   applied.
 *
 * THE TRAIL. The reader may choose one page per step. A step counts only
 * the visits that match every choice made on an EARLIER step, so a choice
 * narrows what follows it and never the step it sits on: the step still
 * shows the alternatives the reader did not take.
 *
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
 */
class TrafficPaths {

	/**
	 * The pages a step lists before the rest become one "+N more" node.
	 */
	public const TOP = 5;

	/**
	 * The most steps after the start or end point.
	 */
	public const MAX_STEPS = 10;

	/**
	 * The most page views of one visit that are kept. A visit longer than
	 * this is a crawler that slipped through, not a reader, and its tail
	 * would only make the fold bigger.
	 */
	public const MAX_VIEWS = 100;

	/**
	 * Read forward from the start point, or backward from the end point.
	 *
	 * @var string[]
	 */
	public const MODES = ['start', 'end'];

	/**
	 * What joins the pages of a path into one fold key. A NUL byte, because
	 * it is the one character a stored path cannot carry.
	 */
	private const JOIN = "\0";

	/**
	 * Constructor.
	 *
	 * @param TrafficPagePath $pagePath Keys a page view by its in-site route.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TrafficPagePath $pagePath = new TrafficPagePath(),
	) {
	}

	/**
	 * A visit's page views, in order, with reloads collapsed.
	 *
	 * @param array<string, mixed> $session A session from the sessioniser (`events` in journey order).
	 *
	 * @return string[] The paths, at most MAX_VIEWS.
	 *
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
	 */
	public function sequence(array $session): array {
		$out = [];
		$events = $session['events'] ?? [];
		if (is_array($events) === false) {
			return [];
		}

		foreach ($events as $event) {
			if (is_array($event) === false || ($event['name'] ?? '') !== 'page_view') {
				continue;
			}

			$path = $this->path(event: $event);
			if ($out !== [] && $out[count($out) - 1] === $path) {
				continue;
			}

			$out[] = $path;
			if (count($out) >= self::MAX_VIEWS) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Fold visits into a map of path => visits, adding to what is there.
	 *
	 * A visit without a page view is not a path and is left out.
	 *
	 * @param array<string, int>               $folded   What earlier days folded.
	 * @param array<int, array<string, mixed>> $sessions The sessions to add.
	 *
	 * @return array<string, int> The fold, keyed by the pages joined with NUL.
	 *
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-paths-endpoint-must-count-each-visits-own-path-from-the-raw-events
	 */
	public function fold(array $folded, array $sessions): array {
		foreach ($sessions as $session) {
			$sequence = $this->sequence(session: $session);
			if ($sequence === []) {
				continue;
			}

			$key = implode(self::JOIN, $sequence);
			$folded[$key] = ($folded[$key] ?? 0) + 1;
		}

		return $folded;
	}

	/**
	 * The explorer: one column per step and the links between them.
	 *
	 * @param array<string, int>  $folded The fold from `fold()`.
	 * @param string              $mode   `start` (read forward) or `end` (read backward).
	 * @param string              $anchor A page to start or end at, '' for a visit's start or end.
	 * @param int                 $steps  How many steps after step 0, 1 to MAX_STEPS.
	 * @param array<int, ?string> $trail  The chosen page per step, null or '' for none.
	 *
	 * @return array{sessions: int, columns: array<int, array<string, mixed>>,
	 *               links: array<int, array{source: int, target: int, step: int, sessions: int}>} The explorer.
	 *
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-start-from-a-visits-start-or-a-page-or-end-at-a-visits-end-or-a-page
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-reader-must-be-able-to-expand-from-a-node-and-change-the-number-of-steps
	 */
	public function explore(array $folded, string $mode, string $anchor, int $steps, array $trail): array {
		$steps = max(1, min(self::MAX_STEPS, $steps));
		$paths = $this->anchored(folded: $folded, backward: ($mode === 'end'), anchor: $anchor, steps: $steps);
		$trail = $this->normaliseTrail(trail: $trail, steps: $steps);

		$columns = [];
		$indexes = [];
		for ($step = 0; $step <= $steps; $step++) {
			[$columns[$step], $indexes[$step]] = $this->column(paths: $paths, step: $step, trail: $trail);
		}

		$links = [];
		for ($step = 0; $step < $steps; $step++) {
			foreach ($this->links(paths: $paths, step: $step, trail: $trail, from: $indexes[$step], to: $indexes[$step + 1]) as $link) {
				$links[] = $link;
			}
		}

		return ['sessions' => $columns[0]['sessions'], 'columns' => $columns, 'links' => $links];
	}

	/**
	 * The trail as a list indexed by step, '' for "no choice", no longer
	 * than the steps shown.
	 *
	 * @param array<int, ?string> $trail The trail as given.
	 * @param int                 $steps The steps after step 0.
	 *
	 * @return string[] The trail.
	 */
	private function normaliseTrail(array $trail, int $steps): array {
		$out = [];
		foreach (array_slice(array_values($trail), 0, $steps + 1) as $choice) {
			$out[] = (string)($choice ?? '');
		}

		return $out;
	}

	/**
	 * Every visit's path from the start or end point, as `[pages, weight, length]`.
	 *
	 * `pages` is cut to the steps shown; `length` is how many pages the
	 * visit had from the anchor on, so a visit that was cut is not mistaken
	 * for one that ended.
	 *
	 * @param array<string, int> $folded   The fold.
	 * @param bool               $backward Read from the end.
	 * @param string             $anchor   The page, or ''.
	 * @param int                $steps    The steps after step 0.
	 *
	 * @return array<int, array{0: string[], 1: int, 2: int}> The paths.
	 */
	private function anchored(array $folded, bool $backward, string $anchor, int $steps): array {
		$out = [];
		foreach ($folded as $key => $weight) {
			$pages = explode(self::JOIN, (string)$key);
			if ($backward === true) {
				$pages = array_reverse($pages);
			}

			if ($anchor !== '') {
				// First view going forward, which is the LAST view when the
				// pages were reversed: a visit ends at a page from its last
				// view of it, and starts from its first.
				$position = array_search($anchor, $pages, true);
				if ($position === false) {
					continue;
				}

				$pages = array_slice($pages, (int)$position);
			}

			$out[] = [array_slice($pages, 0, $steps + 1), (int)$weight, count($pages)];
		}

		return $out;
	}

	/**
	 * Whether a path matches every choice made on a step before `$step`.
	 *
	 * @param string[] $pages The path's pages.
	 * @param string[] $trail The choices.
	 * @param int      $step  The step the path is counted on.
	 *
	 * @return bool True when it matches.
	 */
	private function inScope(array $pages, array $trail, int $step): bool {
		$until = min($step, count($trail));
		for ($i = 0; $i < $until; $i++) {
			if ($trail[$i] !== '' && ($pages[$i] ?? null) !== $trail[$i]) {
				return false;
			}
		}

		return true;
	}

	/**
	 * One step: its top pages, its "+N more" node, and the node index of
	 * every page on it.
	 *
	 * @param array<int, array{0: string[], 1: int, 2: int}> $paths The paths.
	 * @param int                                           $step  The step.
	 * @param string[]                                      $trail The choices.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, int>} The column, and page => node index (a page in "+N more" maps to that node).
	 */
	private function column(array $paths, int $step, array $trail): array {
		[$visits, $ended] = $this->count(paths: $paths, step: $step, trail: $trail);

		// Keys cast back to strings: PHP turns a numeric-string key into an
		// int, and a page path is a string whatever it looks like.
		$ranked = array_map('strval', array_keys($visits));
		usort($ranked, static fn (string $a, string $b): int => [$visits[$b], $a] <=> [$visits[$a], $b]);
		$chosen = ($trail[$step] ?? '');

		$shown = array_slice($ranked, 0, self::TOP);
		if ($chosen !== '' && isset($visits[$chosen]) === true && in_array($chosen, $shown, true) === false) {
			$shown[] = $chosen;
		}

		$nodes = [];
		$index = [];
		foreach ($shown as $page) {
			$index[$page] = count($nodes);
			$nodes[] = ['path' => $page, 'sessions' => $visits[$page], 'dropOffs' => $ended[$page], 'more' => 0, 'selected' => ($page === $chosen)];
		}

		$more = ['path' => '', 'sessions' => 0, 'dropOffs' => 0, 'more' => 0, 'selected' => false];
		foreach (array_diff($ranked, $shown) as $page) {
			$more['sessions'] += $visits[$page];
			$more['dropOffs'] += $ended[$page];
			$more['more']++;
			$index[$page] = count($nodes);
		}

		if ($more['more'] > 0) {
			$nodes[] = $more;
		}

		return [
			[
				'step' => $step,
				'sessions' => array_sum($visits),
				'dropOffs' => array_sum($ended),
				'nodes' => $nodes,
			],
			$index,
		];
	}

	/**
	 * The visits on each page of one step, and how many of them ended there.
	 *
	 * @param array<int, array{0: string[], 1: int, 2: int}> $paths The paths.
	 * @param int                                           $step  The step.
	 * @param string[]                                      $trail The choices.
	 *
	 * @return array{0: array<string, int>, 1: array<string, int>} Page => visits, page => drop-offs.
	 */
	private function count(array $paths, int $step, array $trail): array {
		$visits = [];
		$ended = [];
		foreach ($paths as [$pages, $weight, $length]) {
			if (isset($pages[$step]) === false || $this->inScope(pages: $pages, trail: $trail, step: $step) === false) {
				continue;
			}

			$page = $pages[$step];
			$visits[$page] = ($visits[$page] ?? 0) + $weight;
			$ended[$page] = ($ended[$page] ?? 0) + ($weight * (int)($length === $step + 1));
		}

		return [$visits, $ended];
	}

	/**
	 * The links from one step to the next, one per pair of nodes.
	 *
	 * Counted over the visits in scope of the NEXT step, so a choice on
	 * this step leaves links from the chosen node only.
	 *
	 * @param array<int, array{0: string[], 1: int, 2: int}> $paths The paths.
	 * @param int                                           $step  The step the links leave.
	 * @param string[]                                      $trail The choices.
	 * @param array<string, int>                            $from  Page => node index on this step.
	 * @param array<string, int>                            $to    Page => node index on the next step.
	 *
	 * @return array<int, array{source: int, target: int, step: int, sessions: int}> The links, busiest first.
	 */
	private function links(array $paths, int $step, array $trail, array $from, array $to): array {
		$counts = [];
		foreach ($paths as [$pages, $weight]) {
			if (isset($pages[$step + 1]) === false || $this->inScope(pages: $pages, trail: $trail, step: $step + 1) === false) {
				continue;
			}

			$pair = $from[$pages[$step]] . ':' . $to[$pages[$step + 1]];
			$counts[$pair] = ($counts[$pair] ?? 0) + $weight;
		}

		$out = [];
		foreach ($counts as $pair => $sessions) {
			[$source, $target] = array_map('intval', explode(':', (string)$pair));
			$out[] = ['step' => $step, 'source' => $source, 'target' => $target, 'sessions' => $sessions];
		}

		usort($out, static fn (array $a, array $b): int => [$b['sessions'], $a['source'], $a['target']] <=> [$a['sessions'], $b['source'], $b['target']]);

		return $out;
	}

	/**
	 * The in-site route of an event's page, by the same rule as the daily
	 * figures (portal-page-traffic), so a page reads the same here, in the
	 * Pages widget and on its own detail page.
	 *
	 * @param array<string, mixed> $event The event.
	 *
	 * @return string The route.
	 */
	private function path(array $event): string {
		return $this->pagePath->ofEvent(event: $event);
	}
}
