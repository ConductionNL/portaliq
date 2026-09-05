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
 * object (found by portal and date, saved with its uuid). Running twice
 * therefore gives the same numbers, and nothing here adds to a counter.
 *
 * A day with no raw events left is NOT rewritten: its events may have
 * been purged since the rollup was computed, and the rollup is exactly
 * what is meant to outlive them.
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
	 *
	 * @return void
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
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = trim((string)($portal['slug'] ?? ''));
			if ($slug === '') {
				continue;
			}

			$portalsDone++;
			$daysDone += $this->aggregatePortal(portal: $portal, slug: $slug, now: $now);
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
	 * @param array<string, mixed> $portal The portal record.
	 * @param string               $slug   Its slug.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return int How many days were rewritten.
	 */
	private function aggregatePortal(array $portal, string $slug, DateTimeImmutable $now): int {
		$config = $this->config->resolve(portal: $portal);
		$watermarkKey = self::WATERMARK_PREFIX . $slug;
		$since = $this->appConfig->getValueString(Application::APP_ID, $watermarkKey, '');

		$days = $this->daysToRecompute(slug: $slug, since: $since, now: $now);
		$done = 0;
		foreach ($days as $date) {
			$done += (int)$this->aggregateDay(
				slug: $slug,
				date: $date,
				timeoutMinutes: (int)$config['sessionTimeoutMinutes'],
				now: $now,
				options: [
					'persistClientId' => (($config['sensitive']['persistClientId'] ?? false) === true),
					'accountLinking' => (($config['sensitive']['accountLinking'] ?? false) === true),
					'goals' => ($config['goals'] ?? []),
					'funnels' => ($config['funnels'] ?? []),
					'customDimensions' => ($config['customDimensions'] ?? []),
				]
			);
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
	 * Rebuild one portal-day from its raw events and save it over the
	 * existing rollup.
	 *
	 * @param string            $slug           The portal slug.
	 * @param string            $date           The UTC day.
	 * @param int                 $timeoutMinutes The portal's session timeout.
	 * @param DateTimeImmutable   $now            The clock.
	 * @param array<string, mixed> $options       The portal's switches and its goal, funnel and dimension definitions.
	 *
	 * @return bool True when a rollup was written.
	 */
	private function aggregateDay(string $slug, string $date, int $timeoutMinutes, DateTimeImmutable $now, array $options): bool {
		$from = $date . 'T00:00:00.000Z';
		$to = (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d\TH:i:s.v\Z');
		$events = $this->store->eventsBetween(portal: $slug, from: $from, to: $to);
		if ($events === []) {
			return false;
		}

		$sessions = $this->sessioniser->sessions(events: $events, timeoutMinutes: $timeoutMinutes);
		$record = $this->rollup->build(
			portal: $slug,
			date: $date,
			sessions: $sessions,
			aggregatedAt: $now->format('Y-m-d\TH:i:s\Z'),
			options: $options
		);

		$existing = $this->store->findDaily(portal: $slug, date: $date);
		$uuid = null;
		if ($existing !== null) {
			$uuid = trim((string)($existing['@self']['uuid'] ?? $existing['uuid'] ?? $existing['@self']['id'] ?? $existing['id'] ?? ''));
			if ($uuid === '') {
				$uuid = null;
			}
		}

		return $this->store->saveDaily(rollup: $record, uuid: $uuid);
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
