<?php

/**
 * Contract of PortalAuditHook
 *
 * The public surface of PortalAuditHook that other components call, so they depend
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
 * Contract of PortalAuditHook.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAuditHookInterface {

	/**
	 * Record a `download` event, guarded so an absent/failing audit service
	 * never fails the download it is auditing.
	 *
	 * @param string $subjectRef The subject the download belongs to.
	 * @param string $organisation The subject's tenant.
	 * @param string $register The target register.
	 * @param string $schema The target schema.
	 * @param string $id The target object id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#download-emits-an-audit-hook
	 */
	public function download(string $subjectRef, string $organisation, string $register, string $schema, string $id): void;
}//end interface
