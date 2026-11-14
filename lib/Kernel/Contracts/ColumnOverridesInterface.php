<?php

/**
 * Contract of ColumnOverrides
 *
 * The public surface of ColumnOverrides that other components call, so they depend
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
 * Contract of ColumnOverrides.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ColumnOverridesInterface {

	/**
	 * The rows, each case row with its type's `_columns`.
	 *
	 * @param array<int, mixed> $rows The rows.
	 * @param array<string, mixed> $collection The collection they belong to.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
	 */
	public function rows(array $rows, array $collection): array;

	/**
	 * One case row with its type's `_columns`, keyed by field; a row of a
	 * type with no settings is returned as it is.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $collection The collection it belongs to.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
	 */
	public function row(array $row, array $collection): array;
}//end interface
