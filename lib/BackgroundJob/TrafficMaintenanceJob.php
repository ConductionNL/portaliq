<?php

/**
 * Portaliq Traffic Maintenance Job
 *
 * Summarises each measuring portal's traffic into durable daily rows, then
 * deletes the raw events that are past that portal's retention window.
 *
 * WITHOUT THIS JOB, RETENTION IS A SETTING THAT DOES NOTHING. `retentionDays`
 * was declared, documented and enforced nowhere — the decision existed as a
 * pure, tested function with no caller, which is this codebase's single most
 * repeated defect and the reason the task list calls it out by name.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
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

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Service\PortalTrafficReporter;
use OCA\Portaliq\Service\PortalUnscopedStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The hourly traffic sweep.
 *
 * @spec openspec/changes/portal-traffic-analytics/tasks.md
 */
class TrafficMaintenanceJob extends TimedJob {

	/**
	 * The register portals live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The portal schema.
	 */
	private const PORTAL_SCHEMA = 'portal';

	/**
	 * How often this runs, in seconds.
	 *
	 * HOURLY, NOT DAILY. Retention is expressed in days, so a daily run would
	 * be enough for the deletion — but the aggregates are also what the Traffic
	 * figures are read from once raw events start expiring, and a portal whose
	 * numbers only move once a day looks broken to whoever is watching it.
	 * Re-running a day is free by construction: the write is keyed on
	 * `(portal, day)` and replaces.
	 */
	private const INTERVAL = 3600;


	/**
	 * @param ITimeFactory         $time    The clock.
	 * @param PortalUnscopedStore   $store    Reads the portal list.
	 * @param PortalTrafficReporter $reporter Does the work.
	 * @param LoggerInterface       $logger   Records the outcome.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly PortalUnscopedStore $store,
		private readonly PortalTrafficReporter $reporter,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);

		// `seconds`, not `interval`. phpcs requires named arguments on calls
		// into framework code, and a guessed name is a fatal at construction
		// rather than a lint failure — this one threw
		// "Unknown named parameter $interval" the first time the job ran.
		// Read the signature; do not infer it from the setter's name.
		$this->setInterval(seconds: self::INTERVAL);
	}//end __construct()


	/**
	 * Sweep every portal that measures anything.
	 *
	 * ONE PORTAL'S FAILURE IS NOT THE FLEET'S. Each portal is handled inside
	 * its own try/catch, so an unreachable register or a malformed record
	 * costs that portal its run rather than stopping every portal after it in
	 * the list — the failure mode where one broken tenant silently freezes
	 * everyone else's retention.
	 *
	 * Nothing is ever thrown out of here. An exception escaping to the cron
	 * runner takes down the whole job for every app on the instance.
	 *
	 * @param mixed $argument Unused.
	 *
	 * @return void
	 */
	protected function run($argument): void {
		$now = $this->time->getTime();

		try {
			$portals = $this->store->readObjects(
				register: self::REGISTER,
				schema: self::PORTAL_SCHEMA,
				filters: []
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[portaliq] traffic maintenance could not list portals',
				['reason' => $e->getMessage()]
			);
			return;
		}

		$sweptDays = 0;
		$deleted = 0;
		$portalsSwept = 0;

		foreach ($portals as $portal) {
			try {
				$result = $this->reporter->runMaintenance(portal: $portal, now: $now);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[portaliq] traffic maintenance failed for one portal',
					['portal' => (string)($portal['slug'] ?? ''), 'reason' => $e->getMessage()]
				);
				continue;
			}

			if ($result['days'] === 0 && $result['deleted'] === 0) {
				continue;
			}

			$portalsSwept++;
			$sweptDays += $result['days'];
			$deleted += $result['deleted'];
		}

		// LOGGED EVEN WHEN IT DID NOTHING would be noise; logged when it DID
		// something is what makes a retention sweep auditable — "we delete
		// after N days" is a claim somebody eventually has to evidence.
		if ($portalsSwept > 0) {
			$this->logger->info(
				'[portaliq] traffic maintenance complete',
				['portals' => $portalsSwept, 'days' => $sweptDays, 'eventsDeleted' => $deleted]
			);
		}
	}//end run()


}//end class
