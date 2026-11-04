<?php

/**
 * The steps an event passes on its way to storage
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Traffic
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

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\TrafficConfigResolver;
use OCA\Portaliq\Service\TrafficEventValidator;

/**
 * Finds the portal, resolves its settings, validates the event and stores the record.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */
final class TrafficIngestPipeline {
	/**
	 * Constructor.
	 *
	 * @param PortalResolver        $portals   Finds the published portal.
	 * @param TrafficConfigResolver $config    Resolves what the portal measures.
	 * @param TrafficEventValidator $validator Admits or refuses an event.
	 * @param TrafficEventStore     $store     Stores the records.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function __construct(
		public readonly PortalResolver $portals,
		public readonly TrafficConfigResolver $config,
		public readonly TrafficEventValidator $validator,
		public readonly TrafficEventStore $store,
	) {
	}//end __construct()
}//end class
