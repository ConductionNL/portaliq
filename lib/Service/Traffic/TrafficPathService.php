<?php

/**
 * Portaliq Traffic Path Service.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Reads a portal's raw events for a period and hands the path explorer
 * its steps. `TrafficPaths` does the counting; this class decides WHAT is
 * read and says how much of the period that turned out to be.
 *
 * DAY BY DAY, NEWEST FIRST. Each UTC day is read, grouped into visits by
 * the same sessioniser and timeout as the daily figures, narrowed to the
 * segment, and folded into weighted paths before the next day is read.
 * Only one day's events are ever held, and a visit that crosses midnight
 * is two visits, exactly as in the daily figures.
 *
 * TWO LIMITS, BOTH SAID OUT LOUD.
 * - Retention. Raw events are purged after the portal's `retentionDays`.
 *   Days before the first kept day are not read, and `coverage` names the
 *   days that were.
 * - The event cap. One read scans at most MAX_EVENTS events. Measured on
 *   2026-09-23 against OpenRegister on the dev stack: 20,000 events read in
 *   3.2 to 5.4 s, so the cap is roughly 8 to 14 seconds of reading; a lean
 *   row holds about 720 bytes, so the day in hand stays in tens of
 *   megabytes. Grouping and counting 20,000 events took 0.13 s. When it is hit the
 *   day in hand is used as far as it was read, the older days are not
 *   read at all, and the answer says `truncated` and which day was partial.
 *
 * FIVE MINUTES OF CACHE. The fold of one portal, period, segment and
 * timeout is cached, so choosing a node, adding a step or switching
 * between start and end reuses it rather than reading the events again.
 * The daily figures refresh every fifteen minutes; five minutes of paths
 * lagging behind them is the smaller surprise.
 *
 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-say-when-it-shows-less-than-the-chosen-period
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the portals, their
 * configuration, the store, the sessioniser, the segments, the path
 * arithmetic, the cache and the clock are the whole cast of one read; a
 * facade would hide which step reads what.
 */
class TrafficPathService {

	/**
	 * The most events one read scans.
	 */
	public const MAX_EVENTS = 50000;

	/**
	 * How long a fold is cached, in seconds.
	 */
	public const CACHE_TTL = 300;

	/**
	 * The largest fold that is cached, in bytes of JSON. A distributed cache
	 * refuses big values (memcached's default item is 1 MB), and a fold that
	 * big would cost more to fetch than it saves.
	 */
	private const CACHE_MAX_BYTES = 900000;

	/**
	 * The cache the folds live in.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor.
	 *
	 * @param PortalResolver        $portals      Finds the portal by slug.
	 * @param TrafficConfigResolver $config       Resolves its retention, timeout and segments.
	 * @param TrafficEventStore     $store        Reads the raw events.
	 * @param TrafficSessioniser    $sessioniser  Groups a day's events into visits.
	 * @param TrafficSegments       $segments     Narrows the visits to a segment.
	 * @param TrafficPaths          $paths        Folds visits and builds the steps.
	 * @param ICacheFactory         $cacheFactory Creates the fold cache.
	 * @param ITimeFactory          $time         The clock.
	 * @param int                   $maxEvents    The event cap, MAX_EVENTS unless a test needs a smaller one.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- see the class
	 * docblock: each collaborator is one step of the read.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly TrafficEventStore $store,
		private readonly TrafficSessioniser $sessioniser,
		private readonly TrafficSegments $segments,
		private readonly TrafficPaths $paths,
		ICacheFactory $cacheFactory,
		private readonly ITimeFactory $time,
		private readonly int $maxEvents = self::MAX_EVENTS,
	) {
		$this->cache = $cacheFactory->createDistributed('portaliq_traffic_paths');
	}

	/**
	 * The explorer for a portal, a period and a segment.
	 *
	 * @param string              $portal  The portal slug.
	 * @param string              $from    The first day, YYYY-MM-DD.
	 * @param string              $to      The last day, YYYY-MM-DD.
	 * @param string              $segment The segment id, '' for all visits.
	 * @param string              $mode    `start` or `end`.
	 * @param string              $anchor  The page, '' for a visit's start or end.
	 * @param int                 $steps   The steps after step 0.
	 * @param array<int, ?string> $trail   The chosen page per step.
	 *
	 * @return array<string, mixed> The explorer and what it covers, or `['error' => reason]`.
	 *
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-say-when-it-shows-less-than-the-chosen-period
	 * @spec openspec/changes/portal-traffic-path-explorer/specs/portal-traffic-path-explorer/spec.md#requirement-the-explorer-must-follow-the-pages-portal-period-and-segment
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the eight are the
	 * request's eight parameters, each validated by the controller.
	 */
	public function explore(
		string $portal,
		string $from,
		string $to,
		string $segment,
		string $mode,
		string $anchor,
		int $steps,
		array $trail
	): array {
		$record = $this->portal(slug: $portal);
		if ($record === null) {
			return ['error' => 'unknown-portal'];
		}

		$config = $this->config->resolve(portal: $record);
		$definition = $this->segment(config: $config, id: $segment);
		if ($segment !== '' && $definition === null) {
			return ['error' => 'unknown-segment'];
		}

		$retention = (int)$config['retentionDays'];
		$today = $this->today();
		$kept = $today->modify('-' . ($retention - 1) . ' days')->format('Y-m-d');
		$first = max($from, $kept);
		$last = min($to, $today->format('Y-m-d'));

		$read = $this->read(
			portal: $portal,
			first: $first,
			last: $last,
			segment: $definition,
			config: $config
		);

		return array_merge(
			[
				'portal' => $portal,
				'from' => $from,
				'to' => $to,
				'segment' => $segment,
				'mode' => $mode,
				'anchor' => $anchor,
				'steps' => $steps,
				'coverage' => [
					'from' => $read['coveredFrom'],
					'to' => $read['coveredTo'],
					'keptFrom' => $kept,
					'retentionDays' => $retention,
					'beyondRetention' => ($from < $kept),
					'partialDay' => $read['partialDay'],
				],
				'truncated' => $read['truncated'],
				'eventCap' => $this->maxEvents,
				'eventsScanned' => $read['scanned'],
				'sessionsRead' => $read['sessions'],
			],
			$this->paths->explore(folded: $read['folded'], mode: $mode, anchor: $anchor, steps: $steps, trail: $trail)
		);
	}

	/**
	 * The fold for the days from `$first` to `$last`, from the cache or
	 * read newest day first under the event cap.
	 *
	 * @param string                    $portal  The portal slug.
	 * @param string                    $first   The first day to read, YYYY-MM-DD.
	 * @param string                    $last    The last day to read, YYYY-MM-DD.
	 * @param array<string, mixed>|null $segment The resolved segment, or null for all visits.
	 * @param array<string, mixed>      $config  The portal's resolved configuration.
	 *
	 * @return array{folded: array<string, int>, coveredFrom: ?string, coveredTo: ?string,
	 *               partialDay: ?string, truncated: bool, scanned: int, sessions: int} The fold and what it covers.
	 */
	private function read(string $portal, string $first, string $last, ?array $segment, array $config): array {
		$timeout = (int)$config['sessionTimeoutMinutes'];
		$key = hash('sha256', implode('|', [$portal, $first, $last, (string)($segment['id'] ?? ''), (string)$timeout]));
		$hit = $this->cached(key: $key);
		if ($hit !== null) {
			return $hit;
		}

		$out = ['folded' => [], 'coveredFrom' => null, 'coveredTo' => null, 'partialDay' => null, 'truncated' => false, 'scanned' => 0, 'sessions' => 0];
		$goals = (array)($config['goals'] ?? []);
		for ($day = $last; $day >= $first; $day = $this->shift(day: $day, offset: '-1 day')) {
			// The cap was reached exactly at the end of the newer day: this
			// day is not read at all, so it is not covered either.
			if ($out['scanned'] >= $this->maxEvents) {
				$out['truncated'] = true;
				break;
			}

			$page = $this->store->eventsForPaths(
				portal: $portal,
				from: $day . 'T00:00:00.000Z',
				to: $this->shift(day: $day, offset: '+1 day') . 'T00:00:00.000Z',
				limit: $this->maxEvents - $out['scanned']
			);
			$out['scanned'] += count($page['events']);
			$out['coveredTo'] ??= $day;
			$out['coveredFrom'] = $day;

			$sessions = $this->sessioniser->sessions(events: $page['events'], timeoutMinutes: $timeout);
			if ($segment !== null) {
				$sessions = $this->segments->filter(segment: $segment, sessions: $sessions, goals: $goals);
			}

			$out['sessions'] += count($sessions);
			$out['folded'] = $this->paths->fold(folded: $out['folded'], sessions: $sessions);

			if ($page['truncated'] === true) {
				$out['truncated'] = true;
				$out['partialDay'] = $day;
				break;
			}
		}

		$this->remember(key: $key, value: $out);

		return $out;
	}

	/**
	 * A cached fold, or null.
	 *
	 * @param string $key The cache key.
	 *
	 * @return array{folded: array<string, int>, coveredFrom: ?string, coveredTo: ?string,
	 *               partialDay: ?string, truncated: bool, scanned: int, sessions: int}|null The fold.
	 */
	private function cached(string $key): ?array {
		$raw = $this->cache->get($key);
		if (is_string($raw) === false) {
			return null;
		}

		$value = json_decode($raw, true);
		if (is_array($value) === false || is_array($value['folded'] ?? null) === false) {
			return null;
		}

		/*
		 * @var array{folded: array<string, int>, coveredFrom: ?string, coveredTo: ?string,
		 *            partialDay: ?string, truncated: bool, scanned: int, sessions: int} $value
		 */
		return $value;
	}

	/**
	 * Cache a fold when it is small enough to be worth it.
	 *
	 * @param string               $key   The cache key.
	 * @param array<string, mixed> $value The fold and what it covers.
	 *
	 * @return void
	 */
	private function remember(string $key, array $value): void {
		$json = json_encode($value);
		if (is_string($json) === true && strlen($json) <= self::CACHE_MAX_BYTES) {
			$this->cache->set($key, $json, self::CACHE_TTL);
		}
	}

	/**
	 * The published portal with this slug, or null.
	 *
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null The portal record.
	 */
	private function portal(string $slug): ?array {
		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true && ($portal['slug'] ?? null) === $slug) {
				return $portal;
			}
		}

		return null;
	}

	/**
	 * The resolved segment with this id, or null.
	 *
	 * @param array<string, mixed> $config The portal's resolved configuration.
	 * @param string               $id     The segment id, '' for none.
	 *
	 * @return array<string, mixed>|null The segment.
	 */
	private function segment(array $config, string $id): ?array {
		if ($id === '') {
			return null;
		}

		foreach ((array)($config['segments'] ?? []) as $segment) {
			if (is_array($segment) === true && (string)($segment['id'] ?? '') === $id) {
				return $segment;
			}
		}

		return null;
	}

	/**
	 * A day moved by an offset, as YYYY-MM-DD.
	 *
	 * @param string $day    The day.
	 * @param string $offset The modifier: `-1 day` or `+1 day`.
	 *
	 * @return string The day.
	 */
	private function shift(string $day, string $offset): string {
		return (new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC')))->modify($offset)->format('Y-m-d');
	}

	/**
	 * Today, at UTC midnight.
	 *
	 * @return DateTimeImmutable The day.
	 */
	private function today(): DateTimeImmutable {
		$now = DateTimeImmutable::createFromMutable($this->time->getDateTime())->setTimezone(new DateTimeZone('UTC'));

		return $now->setTime(0, 0);
	}
}
