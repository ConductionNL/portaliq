<?php

/**
 * Contract of CaseStatusLabelField
 *
 * The public surface of CaseStatusLabelField that other components call, so they depend
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
 * Contract of CaseStatusLabelField.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseStatusLabelFieldInterface {

	/**
	 * Stamp the words a case's status reads as onto the row as `_statusLabel`:
	 * the value of the declared `statusLabelField` when it holds text. A row
	 * without them is returned as it is, so "My cases" shows the status as
	 * before, and the raw status is never touched.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param array<string, mixed> $collection The normalised case collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md
	 */
	public function stamp(array $row, array $collection): array;
}//end interface
