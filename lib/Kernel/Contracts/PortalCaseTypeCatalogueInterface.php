<?php

/**
 * Contract of PortalCaseTypeCatalogue
 *
 * The public surface of PortalCaseTypeCatalogue that other components call, so they depend
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
 * Contract of PortalCaseTypeCatalogue.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalCaseTypeCatalogueInterface {

	/**
	 * Every case type the portal can name, each with whether it is shown.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return array<int, array{register: string, schema: string, typeId: string, label: string, shown: bool}>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function listFor(array $portal): array;

	/**
	 * One portal by slug, in any status, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function portalBySlug(string $slug): ?array;

	/**
	 * Write the portal's hidden list; nothing else on the portal changes.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param array<int, mixed> $hidden The case types to hide.
	 *
	 * @return array<string, mixed>|null The saved portal, or null when the write failed.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-nothing-is-deleted-by-hiding-req-osc-003
	 */
	public function save(array $portal, array $hidden): ?array;
}//end interface
