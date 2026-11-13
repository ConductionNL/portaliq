<?php

/**
 * Contract of PortalRegisterContext
 *
 * The public surface of PortalRegisterContext that other components call, so they depend
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
 * Contract of PortalRegisterContext.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalRegisterContextInterface {

	/**
	 * The register every Portaliq schema lives in.
	 *
	 * @var string
	 */
	public const REGISTER = 'portaliq';

	/**
	 * Point an ObjectService at one of this app's schemas.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $schemaSlug    The Portaliq schema slug.
	 *
	 * @return bool True when the context was applied.
	 *
	 * @spec openspec/specs/portal-page-designer/spec.md#requirement-a-cms-read-must-not-inherit-another-apps-openregister-context
	 */
	public function apply(object $objectService, string $schemaSlug): bool;
}//end interface
