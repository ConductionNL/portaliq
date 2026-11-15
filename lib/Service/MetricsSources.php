<?php

/**
 * The readings the metrics endpoint reports
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Traffic\TrafficMetrics;

/**
 * The services the metrics endpoint reads its figures from.
 *
 * @spec openspec/specs/observability/spec.md#REQ-OBS-001
 */
final class MetricsSources {
	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService For the OpenRegister availability check.
	 * @param AuditTrailService $auditor         Count-only audit-entry totals by verb.
	 * @param TrafficMetrics    $traffic         The collector's accepted and refused counters.
	 * @param CmsReader|null    $cms             The public content reader, for its cache hit and miss counts.
	 *
	 * @spec openspec/specs/observability/spec.md#REQ-OBS-001
	 */
	public function __construct(
		public readonly SettingsService $settingsService,
		public readonly AuditTrailService $auditor,
		public readonly TrafficMetrics $traffic,
		public readonly ?CmsReader $cms = null,
	) {
	}//end __construct()
}//end class
