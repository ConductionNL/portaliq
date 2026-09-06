<?php

/**
 * Portaliq Traffic Null Geo Resolver.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The phase 0 geo resolver: it knows nothing, and says so.
 *
 * Stated as a class rather than a null check in the ingest service so that
 * the day an offline database is wired in, the ingest path does not change
 * at all. Until then every portal's `region` is empty whatever granularity it
 * asked for, which is the honest answer.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the contract takes an
 * address and a granularity; this implementation deliberately reads neither.
 */
class NullGeoResolver implements GeoResolverInterface {

	/**
	 * Nothing, for every address.
	 *
	 * @param string $address     The visitor's address, unread.
	 * @param string $granularity The requested granularity, unread.
	 *
	 * @return string|null Always null.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
	 */
	public function resolve(string $address, string $granularity): ?string {
		return null;
	}
}
