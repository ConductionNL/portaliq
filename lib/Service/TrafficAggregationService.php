<?php

/**
 * Portaliq Traffic Aggregation Service.
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

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCA\Portaliq\Service\Traffic\TrafficRollup;
use OCA\Portaliq\Service\Traffic\TrafficRollupSum;
use OCA\Portaliq\Service\Traffic\TrafficSegments;
use OCA\Portaliq\Service\Traffic\TrafficSessioniser;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Recomputes each portal's daily rollups from its raw events, then purges
 * the raw events past their retention.
 *
 * WHICH DAYS. Today and yesterday (UTC) are always recomputed, because a
 * day is not closed until its last late beacon has arrived. On top of
 * that, every event received since the portal's previous run names its
 * own day, so a batch that was delayed for a week still lands in the
 * right rollup rather than being counted under the day it arrived.
 *
 * IDEMPOTENT BY CONSTRUCTION, NOT BY BOOKKEEPING. A day's record is
 * rebuilt from ALL of that day's raw events and saved over the existing
 * object (found by portal, date and segment, saved with its uuid).
 * Running twice therefore gives the same numbers, and nothing here adds
 * to a counter.
 *
 * A day with no raw events left is NOT rewritten: its events may have
 * been purged since the rollup was computed, and the rollup is exactly
 * what is meant to outlive them.
 *
 * SEGMENTS AND ROLL-UPS (portal-traffic-reporting). A portal-day is
 * computed once for all sessions and once per configured segment, each
 * its own record with `segment` set; a segment's record is deleted when
 * the segment is. A roll-up portal has no raw events: its day is the sum
 * of its members' "all sessions" records, computed AFTER every ordinary
 * portal so it reads the numbers this same run wrote.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
 */
class TrafficAggregationService {

	/**
	 * The app config key prefix for each portal's watermark.
	 */
	public const WATERMARK_PREFIX = 'traffic_aggregated_';

	/**
	 * Constructor.
	 *
	 * @param PortalResolver        $portals     Lists the published portals.
	 * @param TrafficConfigResolver $config      Resolves a portal's measurement configuration.
	 * @param TrafficEventStore     $store       Reads raw events, writes rollups, purges.
	 * @param TrafficSessioniser    $sessioniser Groups events into sessions.
	 * @param TrafficRollup         $rollup      Counts a day of sessions.
	 * @param IAppConfig            $appConfig   Holds the per-portal watermark.
	 * @param ITimeFactory          $time        The clock.
	 * @param LoggerInterface       $logger      The logger.
	 * @param TrafficSegments       $segments    Filters sessions into a segment.
	 * @param TrafficRollupSum      $rollupSum   Sums a roll-up portal's members.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the two
	 * collaborators phase 4a adds sit after the eight the earlier phases
	 * injected; a facade over them would hide which step reads what.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly TrafficEventStore $store,
		private readonly TrafficSessioniser $sessioniser,
		private readonly TrafficRollup $rollup,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
		private readonly TrafficSegments $segments = new TrafficSegments(),
		private readonly TrafficRollupSum $rollupSum = new TrafficRollupSum(),
	) {
	}

	/**
	 * Recompute every portal's open days, then purge expired raw events.
	 *
	 * @return array{portals: int, days: int, purged: int} What was done.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 */
	public function run(): array {
		$now = $this->now();
		$portalsDone = 0;
		$daysDone = 0;
		$written = [];
		$rollups = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = trim((string)($portal['slug'] ?? ''));
			if ($slug === '') {
				continue;
			}

			$config = $this->config->resolve(portal: $portal);
			if ($config['rollupOf'] !== []) {
				$rollups[$slug] = $config['rollupOf'];
				continue;
			}

			$portalsDone++;
			$written[$slug] = $this->aggregatePortal(slug: $slug, config: $config, now: $now);
			$daysDone += count($written[$slug]);
		}

		foreach ($rollups as $slug => $members) {
			$portalsDone++;
			$daysDone += $this->aggregateRollup(slug: $slug, members: $members, written: $written, now: $now);
		}

		$purged = $this->store->purgeExpired();
		$this->logger->info(
			'Portaliq: traffic aggregation ran',
			['portals' => $portalsDone, 'days' => $daysDone, 'purged' => $purged]
		);

		return ['portals' => $portalsDone, 'days' => $daysDone, 'purged' => $purged];
	}

	/**
	 * Recompute one portal's open days and move its watermark.
	 *
	 * @param string               $slug   Its slug.
	 * @param array<string, mixed> $config Its resolved configuration.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return array<int, string> The days that were rewritten.
	 */
	private function aggregatePortal(string $slug, array $config, DateTimeImmutable $now): array {
		$watermarkKey = self::WATERMARK_PREFIX . $slug;
		$since = $this->appConfig->getValueString(Application::APP_ID, $watermarkKey, '');

		$days = $this->daysToRecompute(slug: $slug, since: $since, now: $now);
		$done = [];
		foreach ($days as $date) {
			if ($this->aggregateDay(slug: $slug, date: $date, config: $config, now: $now) === true) {
				$done[] = $date;
			}
		}

		$this->appConfig->setValueString(Application::APP_ID, $watermarkKey, $now->format('Y-m-d\TH:i:s\Z'));

		return $done;
	}

	/**
	 * Today, yesterday, and the day of every event received since the
	 * watermark, as UTC dates, oldest first.
	 *
	 * @param string            $slug  The portal slug.
	 * @param string            $since The watermark, ISO 8601, or ''.
	 * @param DateTimeImmutable $now   The clock.
	 *
	 * @return array<int, string> Dates, YYYY-MM-DD.
	 */
	private function daysToRecompute(string $slug, string $since, DateTimeImmutable $now): array {
		$days = [
			$now->modify('-1 day')->format('Y-m-d') => true,
			$now->format('Y-m-d') => true,
		];

		if ($since !== '') {
			foreach ($this->store->receivedSince(since: $since) as $event) {
				if (($event['portal'] ?? '') !== $slug) {
					continue;
				}

				$day = substr((string)($event['occurredAt'] ?? ''), 0, 10);
				if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1) {
					$days[$day] = true;
				}
			}
		}

		$out = array_keys($days);
		sort($out);

		return $out;
	}

	/**
	 * Rebuild one portal-day from its raw events: the "all sessions"
	 * record and one per segment, each saved over its existing rollup, and
	 * the rollups of segments that no longer exist removed.
	 *
	 * @param string               $slug   The portal slug.
	 * @param string               $date   The UTC day.
	 * @param array<string, mixed> $config The portal's resolved configuration.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return bool True when a rollup was written.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-segment-must-be-a-saved-filter-over-sessions
	 */
	private function aggregateDay(string $slug, string $date, array $config, DateTimeImmutable $now): bool {
		$from = $date . 'T00:00:00.000Z';
		$to = (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d\TH:i:s.v\Z');
		$events = $this->store->eventsBetween(portal: $slug, from: $from, to: $to);
		if ($events === []) {
			return false;
		}

		$sessions = $this->sessioniser->sessions(events: $events, timeoutMinutes: (int)$config['sessionTimeoutMinutes']);
		$options = [
			'persistClientId' => (($config['sensitive']['persistClientId'] ?? false) === true),
			'accountLinking' => (($config['sensitive']['accountLinking'] ?? false) === true),
			'goals' => ($config['goals'] ?? []),
			'funnels' => ($config['funnels'] ?? []),
			'customDimensions' => ($config['customDimensions'] ?? []),
		];
		$existing = $this->existingBySegment(slug: $slug, date: $date);
		$aggregatedAt = $now->format('Y-m-d\TH:i:s\Z');

		$wanted = ['' => $sessions];
		foreach ((array)($config['segments'] ?? []) as $segment) {
			$wanted[(string)$segment['id']] = $this->segments->filter(segment: $segment, sessions: $sessions, goals: (array)($config['goals'] ?? []));
		}

		$written = false;
		foreach ($wanted as $segmentId => $segmentSessions) {
			$record = $this->rollup->build(portal: $slug, date: $date, sessions: $segmentSessions, aggregatedAt: $aggregatedAt, options: $options);
			$record['segment'] = (string)$segmentId;
			$written = $this->store->saveDaily(rollup: $record, uuid: ($existing[(string)$segmentId] ?? null)) || $written;
		}

		foreach ($existing as $segmentId => $uuid) {
			if (isset($wanted[$segmentId]) === false) {
				$this->store->deleteDaily(uuid: $uuid);
			}
		}

		return $written;
	}

	/**
	 * Recompute a roll-up portal's days from its members' records.
	 *
	 * The days are today, yesterday and every day a member rewrote in
	 * this run, so a late batch that moved a member's old day moves the
	 * roll-up's too.
	 *
	 * @param string                            $slug    The roll-up portal's slug.
	 * @param string[]                          $members Its member slugs.
	 * @param array<string, array<int, string>> $written The days each ordinary portal rewrote in this run.
	 * @param DateTimeImmutable                 $now     The clock.
	 *
	 * @return int How many days were written.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	private function aggregateRollup(string $slug, array $members, array $written, DateTimeImmutable $now): int {
		$days = [
			$now->modify('-1 day')->format('Y-m-d') => true,
			$now->format('Y-m-d') => true,
		];
		foreach ($members as $member) {
			foreach (($written[$member] ?? []) as $date) {
				$days[$date] = true;
			}
		}

		$done = 0;
		foreach (array_keys($days) as $date) {
			$records = [];
			foreach ($members as $member) {
				$record = $this->store->findDaily(portal: $member, date: (string)$date);
				if ($record !== null) {
					$records[] = $record;
				}
			}

			if ($records === []) {
				continue;
			}

			$summed = $this->rollupSum->sum(
				portal: $slug,
				date: (string)$date,
				members: $members,
				records: $records,
				aggregatedAt: $now->format('Y-m-d\TH:i:s\Z')
			);
			$existing = $this->existingBySegment(slug: $slug, date: (string)$date);
			$done += (int)$this->store->saveDaily(rollup: $summed, uuid: ($existing[''] ?? null));
		}

		return $done;
	}

	/**
	 * The uuids of a portal-day's existing rollups, by segment id.
	 *
	 * @param string $slug The portal slug.
	 * @param string $date The UTC day.
	 *
	 * @return array<string, string> Segment id ('' for all) => uuid.
	 */
	private function existingBySegment(string $slug, string $date): array {
		$out = [];
		foreach ($this->store->findDailyRows(portal: $slug, date: $date) as $row) {
			$uuid = trim((string)($row['@self']['uuid'] ?? $row['uuid'] ?? $row['@self']['id'] ?? $row['id'] ?? ''));
			$segment = trim((string)($row['segment'] ?? ''));
			if ($uuid === '' || isset($out[$segment]) === true) {
				continue;
			}

			$out[$segment] = $uuid;
		}

		return $out;
	}

	/**
	 * The clock, in UTC.
	 *
	 * @return DateTimeImmutable Now.
	 */
	private function now(): DateTimeImmutable {
		return DateTimeImmutable::createFromMutable($this->time->getDateTime())->setTimezone(new DateTimeZone('UTC'));
	}
}
