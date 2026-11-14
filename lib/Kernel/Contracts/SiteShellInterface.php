<?php

/**
 * Contract of SiteShell
 *
 * The public surface of SiteShell that other components call, so they depend
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
 * Contract of SiteShell.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SiteShellInterface {

	/**
	 * Whether this request resolves to a portal at all: by the slug it names
	 * or by its host. False for an unknown host, an unverified domain and an
	 * unknown slug, which the caller answers with 404.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-host-that-resolves-no-portal-must-get-no-site-req-srh-003
	 */
	public function servesAPortal(): bool;

	/**
	 * The template parameters of the site shell.
	 *
	 * @param string $path The path under the site route (`/site/afval`), or ''.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-11-12-portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
	 */
	public function templateParams(string $path = ''): array;

	/**
	 * The portal slug this request names (`?portal=`, else the portal of
	 * `?org=`), or '' when the host decides.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/site-portal-parity/spec.md#requirement-old-portal-links-must-land-on-the-site-req-srp-048
	 */
	public function requestedPortalSlug(): string;
}//end interface
