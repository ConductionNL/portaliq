<?php

/**
 * Contract of PortalSchemaReader
 *
 * The public surface of PortalSchemaReader that other components call, so they depend
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
 * Contract of PortalSchemaReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSchemaReaderInterface {

	/**
	 * Resolve a schema's serialised definition by slug (RBAC bypassed).
	 *
	 * @param string $slug The schema slug (the manifest already authorised it).
	 *
	 * @return array<string, mixed>|null The schema as an array (with `properties`),
	 *                                   or null when unavailable/not found.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#scoped-schema-introspection
	 */
	public function readSchema(string $slug): ?array;
}//end interface
