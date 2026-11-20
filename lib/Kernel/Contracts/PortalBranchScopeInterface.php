<?php

/**
 * Contract of PortalBranchScope
 *
 * The public surface of PortalBranchScope that other components call, so they depend
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
 * Contract of PortalBranchScope.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalBranchScopeInterface {

	/**
	 * Whether the session's branch lets it see one row of a collection.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param array<string, mixed> $collection The declared collection.
	 * @param array<string, mixed> $row A row the subject scope already allowed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
	 */
	public function admits(array $subject, array $collection, array $row): bool;

	/**
	 * The rows of a collection the session's branch lets it see.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param array<string, mixed> $collection The declared collection.
	 * @param array<int, mixed> $rows The rows the subject scope already allowed.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
	 */
	public function rows(array $subject, array $collection, array $rows): array;

	/**
	 * Stamp the session's branch on a record it files, on the field the
	 * action declares as `branchField`, the way the writer stamps the
	 * subject on `scopeField`. The client's own value for that field is
	 * never kept.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param array<string, mixed> $action The declared create action.
	 * @param array<string, mixed> $data The record to write.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
	 */
	public function stamp(array $subject, array $action, array $data): array;
}//end interface
