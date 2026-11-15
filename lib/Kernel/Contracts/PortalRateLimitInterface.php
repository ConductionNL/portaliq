<?php

/**
 * Contract of PortalRateLimit
 *
 * The public surface of PortalRateLimit that other components call, so they depend
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
 * Contract of PortalRateLimit.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRateLimitInterface {

	/**
	 * Count one call to an endpoint and answer 429 when it is over its limit:
	 * per subject for a session, per IP address without one.
	 *
	 * @param string                    $endpoint A short name of the endpoint.
	 * @param array<string, mixed>|null $subject  The resolved portal subject, or null.
	 *
	 * @return JSONResponse|null 429, or null when the call may go on.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-signed-in-portal-session-must-be-rate-limited-per-subject
	 */
	public function refusal(string $endpoint, ?array $subject): ?JSONResponse;
}//end interface
