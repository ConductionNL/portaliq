<?php

/**
 * Contract of TrafficBackfillService
 *
 * The public surface of TrafficBackfillService that other components call, so they depend
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

/**
 * Contract of TrafficBackfillService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficBackfillServiceInterface {

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
	 * @spec openspec/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	public function backfill(?string $only = null): array;

	/**
	 * Back-fill once per VERSION: the first job run after an upgrade that
	 * raised it does the work, every later run returns at once.
	 *
	 * @return int The days rewritten, 0 when it had already run.
	 *
	 * @spec openspec/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
	 */
	public function runOnce(): int;
}//end interface
