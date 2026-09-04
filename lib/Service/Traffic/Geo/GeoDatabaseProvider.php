<?php

/**
 * Portaliq Traffic Geo Database Provider.
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
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic\Geo;

use RuntimeException;

/**
 * Somewhere an MMDB city database comes from.
 *
 * A provider knows one thing: how to put an UNCOMPRESSED database at a
 * path the caller names. It does not know where the file will live, when
 * it is refreshed, or whether it opened. The refresh service owns those,
 * so a provider is a download and nothing else, which is what makes the
 * second one cheap.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
 */
interface GeoDatabaseProvider {

	/**
	 * The provider id, as the settings name it.
	 *
	 * @return string One of GeoSettings::PROVIDERS, never `none`.
	 */
	public function providerId(): string;

	/**
	 * The attribution line the operator owes for using the data.
	 *
	 * @return string The attribution, stored beside the database.
	 */
	public function attribution(): string;

	/**
	 * Fetch the database and write it, uncompressed, to the target path.
	 *
	 * @param string $targetPath Where the MMDB file must end up.
	 *
	 * @return string What was fetched, for the log: a URL or an edition.
	 *
	 * @throws RuntimeException When nothing usable could be fetched.
	 */
	public function fetch(string $targetPath): string;
}
