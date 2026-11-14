<?php

/**
 * Contract of GeoRefreshService
 *
 * The public surface of GeoRefreshService that other components call, so they depend
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
 * Contract of GeoRefreshService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GeoRefreshServiceInterface {

	/**
	 * Download, verify, install, and tell integriq what the refresh met.
	 *
	 * The report reaches integriq's connection registry from all three
	 * callers alike (adopt-connection-registry). It never changes the result.
	 *
	 * @return array{status: string, provider: string, message: string} `disabled`, `refreshed` or `failed`, and why.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-portaliq-conn-002-a-geography-save-refreshes-and-a-refresh-or-a-failed-open-reports
	 */
	public function refresh(): array;

	/**
	 * What the admin panel and the command show: the provider, whether a
	 * database is installed, and its provenance.
	 *
	 * @return array{provider: string, present: bool, path: string|null, metadata: array<string, mixed>} The status.
	 *
	 * @spec openspec/specs/portal-traffic-visitors-and-geo/spec.md#requirement-the-geography-database-must-be-refreshed-without-an-operator-and-on-demand
	 */
	public function status(): array;
}//end interface
