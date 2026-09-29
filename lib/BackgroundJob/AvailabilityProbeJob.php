<?php

/**
 * Portaliq availability probe job
 *
 * Checks every published portal every five minutes and keeps its
 * availability per day and its outages for thirteen months.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\Availability\AvailabilityProbe;
use OCA\Portaliq\Service\Availability\AvailabilityRollup;
use OCA\Portaliq\Service\Availability\AvailabilityStore;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every five minutes: check each published portal, fold the check (and any
 * intervals missed since the last one) into its records, then remove what
 * is older than thirteen months.
 *
 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
 */
class AvailabilityProbeJob extends TimedJob {
	/**
	 * How long records are kept.
	 */
	private const RETENTION = '-13 months';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Testable clock (job base class).
	 * @param PortalResolver $portals Lists the published portals.
	 * @param AvailabilityProbe $probe Checks one portal.
	 * @param AvailabilityStore $store Keeps the records.
	 * @param AvailabilityRollup $rollup Folds a check into the records.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalResolver $portals,
		private readonly AvailabilityProbe $probe,
		private readonly AvailabilityStore $store,
		private readonly AvailabilityRollup $rollup,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: AvailabilityRollup::INTERVAL);
		$this->setAllowParallelRuns(allow: false);
	}//end __construct()

	/**
	 * Check every published portal, then purge.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-each-published-portal-is-checked-every-five-minutes-req-oar-001
	 * @spec openspec/changes/operate-availability-report/specs/portal-availability/spec.md#requirement-thirteen-months-are-kept-and-no-more-req-oar-003
	 */
	protected function run($argument): void {
		$now = DateTimeImmutable::createFromInterface($this->time->getDateTime())->setTimezone(new DateTimeZone('UTC'));

		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			try {
				$this->checkOne(slug: $slug, now: $now);
			} catch (Throwable $failure) {
				// One portal's failure to record is never another's.
				$this->logger->warning('[AvailabilityProbeJob] Could not record a check: ' . $failure->getMessage(), ['portal' => $slug]);
			}
		}

		$this->store->purgeBefore(cutoff: $now->modify(self::RETENTION)->format('Y-m-d'));
	}//end run()

	/**
	 * Check one portal and store what changed.
	 *
	 * @param string $slug The portal slug.
	 * @param DateTimeImmutable $now This run.
	 *
	 * @return void
	 */
	private function checkOne(string $slug, DateTimeImmutable $now): void {
		$check = $this->probe->check(slug: $slug);

		$today = $now->format('Y-m-d');
		$days = $this->store->dailyBetween(portal: $slug, from: $now->modify('-1 day')->format('Y-m-d'), until: $today);
		if ($this->rollup->lastCheckAt(days: $days) === null) {
			// No check in the last two days: read the whole kept window, so
			// an instance that was off for longer still counts its gap.
			$days = $this->store->dailyBetween(portal: $slug, from: $now->modify(self::RETENTION)->format('Y-m-d'), until: $today);
		}

		$result = $this->rollup->record(
			portal: $slug,
			at: $now,
			status: $check['status'],
			cause: $check['cause'],
			days: $days,
			openOutage: $this->store->openOutage(portal: $slug)
		);

		foreach ($result['days'] as $date => $day) {
			if (($days[$date] ?? null) !== $day) {
				$this->store->save(schema: AvailabilityStore::DAILY_SCHEMA, row: $day);
			}
		}

		foreach ($result['closed'] as $outage) {
			$this->store->save(schema: AvailabilityStore::OUTAGE_SCHEMA, row: $outage);
		}

		if ($result['open'] !== null) {
			$this->store->save(schema: AvailabilityStore::OUTAGE_SCHEMA, row: $result['open']);
		}
	}//end checkOne()
}//end class
