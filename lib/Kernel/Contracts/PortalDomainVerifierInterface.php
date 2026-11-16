<?php

/**
 * Contract of PortalDomainVerifier
 *
 * The public surface of PortalDomainVerifier that other components call, so they depend
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
 * Contract of PortalDomainVerifier.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalDomainVerifierInterface {

	public const VERIFIED = 'verified';

	public const NOT_FOUND = 'not_found';

	/**
	 * The record each domain of a portal must publish. A domain with no token
	 * yet gets one now, and it is kept.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<int, array<string, mixed>>|null Per domain: hostname, name, value, verified and the certificate dates.
	 *                                               Null for an unknown portal.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	public function records(string $slug): ?array;

	/**
	 * Check one domain's record. A record that is not there yet leaves the
	 * domain pending and changes nothing, so the check can run again.
	 *
	 * @param string $slug     The portal slug.
	 * @param string $hostname The domain to check; it must be one of the portal's own.
	 *
	 * @return string|null `verified`, `pending`, or `not_found` for a domain the portal does not list;
	 *                     null when the portal is unknown or the save failed.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/tasks.md#task-3
	 */
	public function verify(string $slug, string $hostname): ?string;
}//end interface
