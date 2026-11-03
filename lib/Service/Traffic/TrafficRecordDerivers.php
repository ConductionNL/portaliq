<?php

/**
 * What a stored record derives from the request
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

/**
 * Turns the address, the agent and the location into the families a record
 * keeps, so the raw values never reach storage.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */
final class TrafficRecordDerivers {
	/**
	 * Constructor.
	 *
	 * @param VisitorHasher        $hasher    Hashes the visitor with the day's salt.
	 * @param UserAgentClassifier  $agents    Device, browser and bot families.
	 * @param ReferrerClassifier   $referrers Path, host, channel and campaign.
	 * @param GeoResolverInterface $geo       The region of an address.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function __construct(
		public readonly VisitorHasher $hasher,
		public readonly UserAgentClassifier $agents,
		public readonly ReferrerClassifier $referrers,
		public readonly GeoResolverInterface $geo,
	) {
	}//end __construct()
}//end class
