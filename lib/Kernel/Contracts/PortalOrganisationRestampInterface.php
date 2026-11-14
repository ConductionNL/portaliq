<?php

/**
 * Contract of PortalOrganisationRestamp
 *
 * The public surface of PortalOrganisationRestamp that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of PortalOrganisationRestamp.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalOrganisationRestampInterface {

	/**
	 * Restamp every row whose organisation slug resolves and differs.
	 *
	 * @return array<string, mixed> See RestampTally::report().
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function apply(): array;

	/**
	 * Count what a restamp would do, and write nothing.
	 *
	 * @return array<string, mixed> See RestampTally::report().
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/portal-acts-for-its-organisation/specs/portal-tenancy/spec.md#requirement-existing-rows-get-their-organisation-from-a-reported-dry-run-then-an-explicit-apply-req-pao-004
	 */
	public function report(): array;
}//end interface
