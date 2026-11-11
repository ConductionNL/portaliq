<?php

/**
 * Portaliq Day Rollup
 *
 * What every record of one portal-day is built with: the portal, the day,
 * the moment of aggregation and the portal's rollup options.
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
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The shared inputs of one TrafficAggregationService::dayRecords() call.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-retained-raw-events-must-be-re-aggregated-into-the-new-page-fields
 */
final class DayRollup {
	/**
	 * Constructor.
	 *
	 * @param string               $portal       The portal slug.
	 * @param string               $date         The UTC day.
	 * @param string               $aggregatedAt The moment of aggregation, ISO 8601.
	 * @param array<string, mixed> $options      The rollup options from the portal's configuration.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $date,
		public readonly string $aggregatedAt,
		public readonly array $options,
	) {
	}//end __construct()
}//end class
