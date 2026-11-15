<?php

/**
 * Contract of PortalCrossRefGuard
 *
 * The public surface of PortalCrossRefGuard that other components call, so they depend
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

use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of PortalCrossRefGuard.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalCrossRefGuardInterface {

	/**
	 * The 403 answer for a body whose cross reference does not resolve, or
	 * null when every declared reference resolves.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<string, mixed> $data The body about to be written.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $app The contributing app.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-declared-cross-reference-must-resolve-inside-the-subjects-own-scope
	 */
	public function refusal(array $action, array $data, array $subject, string $app): ?JSONResponse;

	/**
	 * The first declared reference this body fails on, or an empty string.
	 *
	 * @param array<string, mixed> $action  The matched action, already normalised.
	 * @param array<string, mixed> $data    The whitelisted write body.
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string               $app     The contributing app, for the scope claim.
	 *
	 * @return string The refusing field, empty when every reference is the subject's.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-declared-cross-reference-must-resolve-inside-the-subjects-own-scope
	 */
	public function refusedField(array $action, array $data, array $subject, string $app): string;
}//end interface
