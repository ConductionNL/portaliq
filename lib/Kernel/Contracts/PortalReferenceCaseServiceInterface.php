<?php

/**
 * Contract of PortalReferenceCaseService
 *
 * The public surface of PortalReferenceCaseService that other components call, so they depend
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
 * Contract of PortalReferenceCaseService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalReferenceCaseServiceInterface {

	/**
	 * The case collection a link may be issued for, when the address given
	 * is the address recorded on the case. Null on every other outcome:
	 * no such case, two cases on one number, another address, or a case app
	 * that declares no address field.
	 *
	 * @param string $portal The portal slug.
	 * @param string $register The case type's register.
	 * @param string $schema The case type's schema.
	 * @param string $caseType The case type.
	 * @param string $caseReference The case number.
	 * @param string $email The address the link would go to.
	 * @param string $organisation The tenant.
	 *
	 * @return array{register: string, schema: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the request's own
	 * fields, each part of what must match before anything is issued.
	 */
	public function linkableCase(
		string $portal,
		string $register,
		string $schema,
		string $caseType,
		string $caseReference,
		string $email,
		string $organisation,
	): ?array;

	/**
	 * The one case a reference session reads, projected the way the case
	 * app declared, or null.
	 *
	 * @param array<string, mixed> $reference The resolved reference session.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
	 */
	public function read(array $reference): ?array;
}//end interface
