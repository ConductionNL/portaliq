<?php

/**
 * Portaliq Traffic Rollup Day.
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
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * Which roll-up portal and day a summed record is for: the portal, the
 * UTC day, the configured members, how many of them had a record, and
 * when the sum was computed.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */
final class TrafficRollupDay {

	/**
	 * Constructor.
	 *
	 * @param string   $portal        The roll-up portal's slug.
	 * @param string   $date          The UTC day.
	 * @param string[] $members       The member slugs, as configured.
	 * @param int      $memberRecords How many members had a record.
	 * @param string   $aggregatedAt  When this record was computed.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $date,
		public readonly array $members,
		public readonly int $memberRecords,
		public readonly string $aggregatedAt,
	) {
	}
}
