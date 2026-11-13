<?php

/**
 * Contract of PortalFamilyMembers
 *
 * The public surface of PortalFamilyMembers that other components call, so they depend
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
 * Contract of PortalFamilyMembers.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFamilyMembersInterface {

	/**
	 * The resident's partner and children wherever they live, or null when the
	 * register cannot be asked.
	 *
	 * @param string $subjectRef The session's subject.
	 *
	 * @return array<int, array{ref: string, name: string, relation: string, birthYear: string}>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function allForSubject(string $subjectRef): ?array;

	/**
	 * The resident's partner and children living at the resident's address,
	 * or null when the register cannot be asked.
	 *
	 * Null covers: no account, a login that is not DigiD, no BSN, a provider
	 * that is missing or down. An empty list means the BRP answered and holds
	 * nobody on that address.
	 *
	 * @param string $subjectRef The session's subject.
	 *
	 * @return array<int, array{ref: string, name: string, relation: string, birthYear: string}>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function forSubject(string $subjectRef): ?array;

	/**
	 * The references among those given that are NOT the resident's family now.
	 *
	 * A BRP that cannot be asked refuses every reference: nothing is believed
	 * on the strength of what the browser says.
	 *
	 * @param string   $subjectRef The session's subject.
	 * @param string[] $refs       The chosen references.
	 *
	 * @return string[] The references that do not hold.
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
	 */
	public function forged(string $subjectRef, array $refs): array;
}//end interface
