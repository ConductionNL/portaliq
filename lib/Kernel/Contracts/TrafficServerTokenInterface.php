<?php

/**
 * Contract of TrafficServerToken
 *
 * The public surface of TrafficServerToken that other components call, so they depend
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
 * Contract of TrafficServerToken.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficServerTokenInterface {

	/**
	 * Mint a token for a portal and store its hash on the portal record.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return string|null The token, shown once; null when the portal is unknown or the write failed.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function issue(string $slug): ?string;

	/**
	 * Whether a presented token is the portal's.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 * @param string               $token  The presented token.
	 *
	 * @return bool True when it matches the stored hash.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-server-side-caller-must-hold-the-portals-token
	 */
	public function verify(array $portal, string $token): bool;
}//end interface
