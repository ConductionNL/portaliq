<?php

/**
 * Contract of TrafficAggregationService
 *
 * The public surface of TrafficAggregationService that other components call, so they depend
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
 * Contract of TrafficAggregationService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficAggregationServiceInterface {

	/**
	 * Recompute every portal's open days, then purge expired raw events.
	 *
	 * @return array{portals: int, days: int, purged: int, recordingsPurged: int} What was done.
	 *
	 * @spec openspec/changes/archive/2026-11-04-portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-daily-rollups-must-be-readable-through-the-ordinary-object-api
	 * @spec openspec/specs/portal-traffic-experiments/spec.md#requirement-session-recording-must-be-off-by-default-consented-and-bounded
	 */
	public function run(): array;
}//end interface
