<?php

/**
 * Contract of CaseTypeNames
 *
 * The public surface of CaseTypeNames that other components call, so they depend
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
 * Contract of CaseTypeNames.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypeNamesInterface {

	/**
	 * The rows of one `cases` collection, each with `_caseTypeName` when its
	 * type resolves. The type id is read from the collection's
	 * `caseTypeField` (default `caseType`): a plain id, or a reference
	 * carrying `id` or `uuid`. Any other kind of collection is left alone.
	 *
	 * @param array<int, mixed>    $rows       The collection's rows.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
	 */
	public function stampRows(array $rows, array $collection): array;
}//end interface
