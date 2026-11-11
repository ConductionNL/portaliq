<?php

/**
 * Portaliq Rollup Run
 *
 * One recomputation of a roll-up portal: its slug, its members and the clock.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;

/**
 * The state of one TrafficAggregationService::aggregateRollup() call. Never
 * leaves that class.
 *
 * @internal
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */
final class RollupRun {
	/**
	 * Constructor.
	 *
	 * @param string            $slug    The roll-up portal's slug.
	 * @param string[]          $members Its member slugs.
	 * @param DateTimeImmutable $now     The clock.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly array $members,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
