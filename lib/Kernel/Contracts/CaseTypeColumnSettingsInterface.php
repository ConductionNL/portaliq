<?php

/**
 * Contract of CaseTypeColumnSettings
 *
 * The public surface of CaseTypeColumnSettings that other components call, so they depend
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
 * Contract of CaseTypeColumnSettings.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeColumnSettingsInterface {

	/**
	 * The fields the case apps show in Mijn zaken, each once, with its label.
	 *
	 * @return array<int, array{field: string, label: string}>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function fields(): array;

	/**
	 * Save the columns of one case type, leaving the other types as stored.
	 * An empty list removes the type's settings.
	 *
	 * @param array<string, mixed> $portal The portal.
	 * @param array{typeId: string, columns: array<int|string, mixed>} $change The case type and its columns.
	 *
	 * @return array<string, mixed>|null The saved portal, or null when the write failed.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function save(array $portal, array $change): ?array;

	/**
	 * The stored columns of a portal, per case type id.
	 *
	 * @param array<string, mixed>|null $portal The portal.
	 *
	 * @return array<string, array<int, array{field: string, render: string, linkLabel?: string}>>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-chooses-how-each-column-of-a-case-type-reads
	 */
	public function storedFor(?array $portal): array;
}//end interface
