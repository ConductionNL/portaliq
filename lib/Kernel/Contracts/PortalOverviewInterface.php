<?php

/**
 * Contract of PortalOverview
 *
 * The public surface of PortalOverview that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of PortalOverview.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalOverviewInterface {

	/**
	 * Availability of every published portal over the last full month: each
	 * portal's percentage, all of them together, and the outages.
	 *
	 * @param DateTimeImmutable $now The moment asked for.
	 *
	 * @return array<string, mixed> `month`, `percentage` (null without checks), `outages`,
	 *                              `outageMinutes`, `lastOutage` and `portals` (`portal`, `title`,
	 *                              `percentage`).
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function availability(DateTimeImmutable $now): array;

	/**
	 * Traffic of every published portal over the last days, up to and
	 * including today. Visitors are added per portal, so a person who visited
	 * two portals counts twice.
	 *
	 * @param int $days How many days.
	 * @param DateTimeImmutable $now The moment asked for.
	 *
	 * @return array<string, mixed> `from`, `to`, `days`, `pageViews`, `sessions`, `visitors`,
	 *                              `engagedSessions`, `engagedShare` (percent, null without sessions)
	 *                              and `pages` (`path`, `portal`, `title`, `views`).
	 *
	 * @spec openspec/changes/dashboard-figures-across-portals/specs/dashboard-page/spec.md#requirement-the-dashboard-shows-traffic-and-availability-of-all-portals-together-req-dfp-001
	 */
	public function traffic(int $days, DateTimeImmutable $now): array;
}//end interface
