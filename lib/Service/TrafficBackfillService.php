<?php

/**
 * Portaliq Traffic Back-fill Service.
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
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Traffic\TrafficDayGuard;
use OCA\Portaliq\Service\Traffic\TrafficEventStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Rebuilds every daily record whose raw events are still retained, so a
 * field the daily record gains exists for the whole retention window and
 * not only from the upgrade on (portal-page-traffic).
 *
 * WHY A SEPARATE PASS. The aggregation job only rebuilds today, yesterday
 * and the days a late batch touched. Nothing ever rebuilt an older day,
 * so there was no path to reuse; this is that path, built from the job's
 * own day rebuild (TrafficAggregationService::dayRecords and writeDay), so
 * a back-filled day is exactly the day the job would have written.
 *
 * WHAT IT NEVER DOES. A day with no raw events left keeps its record, as
 * in the job. A day that lost part of its events to the purge keeps its
 * more complete record (TrafficDayGuard). The job's watermark is not
 * touched: this is not the ordinary run.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */
class TrafficBackfillService {

	/**
	 * The app config key that records which back-fill has run.
	 */
	public const DONE_KEY = 'traffic_backfilled';

	/**
	 * The back-fill this code wants. A later change that adds fields to the
	 * daily record raises it, and the next job run back-fills once more.
	 */
	public const VERSION = 'page-traffic-1';

	/**
	 * Constructor.
	 *
	 * @param PortalResolver            $portals     Lists the published portals.
	 * @param TrafficConfigResolver     $config      Resolves a portal's retention and roll-up.
	 * @param TrafficEventStore         $store       Reads the stored day.
	 * @param TrafficAggregationService $aggregation Rebuilds and writes a day, sums a roll-up.
	 * @param IAppConfig                $appConfig   Holds the done marker.
	 * @param ITimeFactory              $time        The clock.
	 * @param TrafficDayGuard           $guard       Tells a partly purged day from a complete one.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly TrafficConfigResolver $config,
		private readonly TrafficEventStore $store,
		private readonly TrafficAggregationService $aggregation,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
		private readonly TrafficDayGuard $guard = new TrafficDayGuard(),
	) {
	}

	/**
	 * Back-fill once per VERSION: the first job run after an upgrade that
	 * raised it does the work, every later run returns at once.
	 *
	 * @return int The days rewritten, 0 when it had already run.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	public function runOnce(): int {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === self::VERSION) {
			return 0;
		}

		$days = $this->backfill()['days'];
		$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, self::VERSION);

		return $days;
	}

	/**
	 * Rebuild every retained day of every ordinary portal, then re-sum the
	 * roll-up portals over the days that changed.
	 *
	 * @param string|null $only One portal slug, or null for every portal. A
	 *                          roll-up portal is re-summed when it has this
	 *                          portal among its members.
	 *
	 * @return array{portals: int, days: int, kept: int, rollupDays: int} Portals walked, days rewritten,
	 *                                                                  days kept as they were, roll-up days re-summed.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	public function backfill(?string $only = null): array {
		$now = DateTimeImmutable::createFromMutable($this->time->getDateTime())->setTimezone(new DateTimeZone('UTC'));
		$written = [];
		$rollups = [];
		$kept = 0;
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

			if ($only !== null && $slug !== $only) {
				continue;
			}

			$written[$slug] = $this->backfillPortal(slug: $slug, config: $config, now: $now);
			$kept += ((int)$config['retentionDays'] + 1) - count($written[$slug]);
		}

		$rollupDays = 0;
		foreach ($rollups as $slug => $members) {
			if ($only === null || in_array($only, $members, true) === true) {
				$rollupDays += $this->aggregation->aggregateRollup(slug: $slug, members: $members, written: $written, now: $now);
			}
		}

		return ['portals' => count($written), 'days' => (int)array_sum(array_map('count', $written)), 'kept' => $kept, 'rollupDays' => $rollupDays];
	}

	/**
	 * Rebuild one portal's retained days, oldest first.
	 *
	 * @param string               $slug   The portal slug.
	 * @param array<string, mixed> $config Its resolved configuration.
	 * @param DateTimeImmutable    $now    The clock.
	 *
	 * @return array<int, string> The days that were rewritten.
	 */
	private function backfillPortal(string $slug, array $config, DateTimeImmutable $now): array {
		$done = [];
		for ($back = (int)$config['retentionDays']; $back >= 0; $back--) {
			$date = $now->modify('-' . $back . ' days')->format('Y-m-d');
			$records = $this->aggregation->dayRecords(slug: $slug, date: $date, config: $config, now: $now);
			if ($records === null) {
				continue;
			}

			if ($this->guard->lostEvents(record: $records[''], stored: $this->store->findDaily(portal: $slug, date: $date)) === true) {
				continue;
			}

			if ($this->aggregation->writeDay(slug: $slug, date: $date, records: $records) === true) {
				$done[] = $date;
			}
		}

		return $done;
	}
}
