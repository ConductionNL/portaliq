<?php

/**
 * Portaliq Traffic Geo Resolver Interface.
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
 * Turns a visitor's address into a coarse region code, or into nothing.
 *
 * The address goes in, a country or region code comes out, and the address
 * is not kept by the caller. Phase 0 ships an implementation that answers
 * null for everything; a later phase adds an offline database. The interface
 * exists now so the ingest path already has the one place geography enters.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
 */
interface GeoResolverInterface {

	/**
	 * The region code for an address, no finer than asked.
	 *
	 * @param string $address     The visitor's address.
	 * @param string $granularity One of `country`, `region`.
	 *
	 * @return string|null A code such as `NL` or `NL-NB`, or null when unknown.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-an-ip-address-must-not-be-stored
	 */
	public function resolve(string $address, string $granularity): ?string;
}
