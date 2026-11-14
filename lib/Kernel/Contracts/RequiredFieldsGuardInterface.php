<?php

/**
 * Contract of RequiredFieldsGuard
 *
 * The public surface of RequiredFieldsGuard that other components call, so they depend
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
 * Contract of RequiredFieldsGuard.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RequiredFieldsGuardInterface {



	/**
	 * The 400 answer for a body that leaves a required field empty, or null
	 * when every required field is filled.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 * @param array<string, mixed>|null $body The body about to be written or forwarded.
	 *
	 * @return JSONResponse|null The refusal, or null to go on.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
	 */
	public function refusal(array $action, ?array $body): ?JSONResponse;

	/**
	 * The refusal of an endpoint forward: 403 when the scoped body did not
	 * resolve (a declared scope claim without a value), else 400 when a
	 * declared `fields` body leaves a required field empty, else null. An
	 * action without `fields` relays the raw body and has no required fields.
	 *
	 * @param array<string, mixed> $action The authorised endpoint action.
	 * @param array{body: array<string, mixed>|null, scopeValue: string}|null $scoped The prepared body, or null.
	 * @param bool $declaresFields Whether the action declares `fields`.
	 *
	 * @return JSONResponse|null The refusal, or null to forward.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-the-server-must-refuse-a-submit-that-leaves-a-required-field-empty-req-smf-024
	 */
	public function forwardRefusal(array $action, ?array $scoped, bool $declaresFields): ?JSONResponse;
}//end interface
