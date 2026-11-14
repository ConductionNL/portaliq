<?php

/**
 * Contract of BearerSessionResolver
 *
 * The public surface of BearerSessionResolver that the controllers and the
 * auth middleware call, so they depend on this contract instead of the
 * implementation.
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
 * Contract of BearerSessionResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BearerSessionResolverInterface {

	/**
	 * Resolve an Authorization header to a server-derived subject. FAILS CLOSED.
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 *
	 * @return array<string, mixed>|null The subject claim set, or null when the
	 *                                   bearer is absent, malformed, forged,
	 *                                   expired, or revoked.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 */
	public function resolveFromBearer(?string $authorizationHeader): ?array;

	/**
	 * Resolve a reference session, and only a reference session. FAILS CLOSED.
	 *
	 * @param string|null $authorizationHeader The raw Authorization header value.
	 *
	 * @return array{subjectRef: string, organisation: string, caseReference: string, register: string, schema: string, jti: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function resolveReferenceFromBearer(?string $authorizationHeader): ?array;
}//end interface
