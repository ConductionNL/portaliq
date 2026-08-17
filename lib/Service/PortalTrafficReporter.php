<?php

/**
 * Portaliq Portal Traffic Reporter
 *
 * Reads traffic back: the figures an operator sees, and the daily summaries
 * that outlive the raw events.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The read side of portal traffic.
 *
 * SEPARATE FROM THE COLLECTOR, and the split is not bookkeeping: writing and
 * reading traffic have opposite risk profiles. `PortalTrafficService` runs on
 * an anonymous public endpoint and its whole job is refusing things; this runs
 * behind a session, deletes rows, and its whole job is not losing anything. The
 * two grew into one class until phpmd measured it at complexity 53 against a
 * threshold of 50, which is the same boundary read a different way.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class PortalTrafficReporter {

	/**
	 * The register these live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The raw events.
	 */
	private const EVENT_SCHEMA = 'portalTrafficEvent';

	/**
	 * The durable daily summaries.
	 */
	private const AGGREGATE_SCHEMA = 'portalTrafficAggregate';


	/**
	 * @param PortalObjectWriter      $writer     Persists the daily summaries.
	 * @param PortalUnscopedStore     $store      Reads events and sweeps expired ones.
	 * @param PortalTrafficAggregator $aggregator Turns events into figures.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly PortalUnscopedStore $store,
		private readonly PortalTrafficAggregator $aggregator,
	) {
	}//end __construct()


	/**
	 * Whether a portal measures anything at all.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return bool True when enabled.
	 */
	private function enabled(array $portal): bool {
		$traffic = (array)($portal['traffic'] ?? []);
		return ($traffic['enabled'] ?? false) === true;
	}//end enabled()


	/**
	 * What this portal's traffic looks like, or that it is not measured.
	 *
	 * A ZERO AND AN UNMEASURED ARE DIFFERENT FACTS, and the distinction is made
	 * HERE rather than left to whatever renders it. A page handed
	 * `{sessions: 0}` has no way to tell "nobody visited" from "nothing was ever
	 * collected", and it will draw an empty chart for both — which reads as the
	 * first. So a portal that does not measure gets `measured: false` and NO
	 * counters at all: there is nothing for a renderer to plot by accident.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 *
	 * @return array<string, mixed> `{measured: false}` or `{measured: true, ...}`.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function summaryFor(array $portal): array {
		if ($this->enabled(portal: $portal) === false) {
			return ['measured' => false];
		}

		$traffic = (array)($portal['traffic'] ?? []);
		$events = $this->store->readObjects(
			register: self::REGISTER,
			schema: self::EVENT_SCHEMA,
			filters: ['portal' => (string)($portal['slug'] ?? '')]
		);

		return array_merge(
			['measured' => true],
			$this->aggregator->aggregate(
				events: $events,
				timeoutMinutes: (int)($traffic['sessionTimeoutMinutes'] ?? 30)
			)
		);
	}//end summaryFor()


	/**
	 * Summarise a portal's traffic into daily rows, then sweep what has expired.
	 *
	 * THE ORDER IS THE WHOLE DESIGN. Aggregates are written FIRST and the raw
	 * events deleted only after; reversing it would delete the rows the summary
	 * is derived from before the summary exists, and a crash between the two
	 * would lose the day permanently. Written this way the worst case is a day
	 * summarised twice, which is exactly what idempotence covers.
	 *
	 * A day is identified by `(portal, day)`. Re-running REPLACES that row
	 * rather than adding a second — otherwise every run would double the
	 * figures, which is the failure that looks like growth.
	 *
	 * @param array<string, mixed> $portal The resolved portal.
	 * @param int                  $now    The current epoch second.
	 *
	 * @return array{days: int, deleted: int} What the run did.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/tasks.md
	 */
	public function runMaintenance(array $portal, int $now): array {
		$nothing = ['days' => 0, 'deleted' => 0];
		if ($this->enabled(portal: $portal) === false) {
			return $nothing;
		}

		$slug = (string)($portal['slug'] ?? '');
		$traffic = (array)($portal['traffic'] ?? []);
		$events = $this->store->readObjects(
			register: self::REGISTER,
			schema: self::EVENT_SCHEMA,
			filters: ['portal' => $slug]
		);

		if ($events === []) {
			return $nothing;
		}

		$days = $this->aggregator->aggregateByDay(
			events: $events,
			timeoutMinutes: (int)($traffic['sessionTimeoutMinutes'] ?? 30)
		);
		$written = $this->writeDays(slug: $slug, days: $days, now: $now);

		// ONLY NOW, and only if EVERY day was written. A sweep that runs when a
		// summary failed would delete the evidence of a day nobody has a record
		// of — which makes writing-first decorative rather than protective.
		if ($written !== count($days)) {
			return ['days' => $written, 'deleted' => 0];
		}

		$deleted = $this->store->deleteObjects(
			register: self::REGISTER,
			schema: self::EVENT_SCHEMA,
			ids: $this->aggregator->expiredIds(
				events: $events,
				retentionDays: (int)($traffic['retentionDays'] ?? 0),
				now: $now
			)
		);

		return ['days' => $written, 'deleted' => $deleted];
	}//end runMaintenance()


	/**
	 * Write each day's summary, replacing any row already there.
	 *
	 * @param string                              $slug The portal slug.
	 * @param array<string, array<string, mixed>> $days Aggregate by day.
	 * @param int                                 $now  The current epoch second.
	 *
	 * @return int How many were written.
	 */
	private function writeDays(string $slug, array $days, int $now): int {
		$existing = $this->existingAggregates(slug: $slug);

		$written = 0;
		foreach ($days as $day => $aggregate) {
			$saved = $this->writer->createAnonymousObject(
				register: self::REGISTER,
				schema: self::AGGREGATE_SCHEMA,
				data: array_merge(
					['portal' => $slug, 'day' => $day, 'computedAt' => gmdate('c', $now)],
					$aggregate
				),
				uuid: (string)($existing[$day] ?? '')
			);

			if ($saved !== null) {
				$written++;
			}
		}

		return $written;
	}//end writeDays()


	/**
	 * The ids of a portal's already-written daily aggregates, by day.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, string> Day to object id.
	 */
	private function existingAggregates(string $slug): array {
		$rows = $this->store->readObjects(
			register: self::REGISTER,
			schema: self::AGGREGATE_SCHEMA,
			filters: ['portal' => $slug]
		);

		$byDay = [];
		foreach ($rows as $row) {
			$day = trim((string)($row['day'] ?? ''));
			$id = trim((string)($row['id'] ?? ''));
			if ($day !== '' && $id !== '') {
				$byDay[$day] = $id;
			}
		}

		return $byDay;
	}//end existingAggregates()


}//end class
