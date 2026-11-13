<?php

/**
 * Contract of SiteReturnAddress
 *
 * The public surface of SiteReturnAddress that other components call, so they depend
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
 * Contract of SiteReturnAddress.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SiteReturnAddressInterface {



	/**
	 * The return address when it is a page of the site, else ''.
	 *
	 * The `/index.php` front controller is optional on both sides, so an
	 * instance with or without pretty URLs compares the same path.
	 *
	 * @param string $candidate The `returnTo` the site sent.
	 * @param string $sitePath  The site route's own path, from the URL generator.
	 *
	 * @return string The accepted address, or ''.
	 *
	 * @spec openspec/changes/archive/2026-11-12-portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	public function accept(string $candidate, string $sitePath): string;
}//end interface
