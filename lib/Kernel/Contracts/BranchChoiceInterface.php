<?php

/**
 * Contract of BranchChoice
 *
 * The public surface of BranchChoice that other components call, so they depend
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
 * Contract of BranchChoice.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BranchChoiceInterface {

	/**
	 * Whether a session may choose one branch, or '' for the whole company.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string               $branch  The branch number, or ''.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	public function allows(array $subject, string $branch): bool;

	/**
	 * The branches a session may choose; empty for a restricted session, a
	 * person, or a company whose branches cannot be read.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/portal-branch-scope/spec.md#requirement-a-whole-company-user-can-narrow-to-a-branch-req-seb-003
	 */
	public function branchesFor(array $subject): array;
}//end interface
