<?php

/**
 * Contract of CitizenWritableSetResolver
 *
 * The public surface of CitizenWritableSetResolver that other components call, so they depend
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
 * Contract of CitizenWritableSetResolver.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenWritableSetResolverInterface {

	/**
	 * Whether one field may be written right now, without rebuilding the whole
	 * set. The refusal path calls this, so the answer a write is judged by and
	 * the answer the citizen was shown come from the same code.
	 *
	 * @param array<string, mixed> $set A resolved set from `resolve()`.
	 * @param string $field The field a write names.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function isWritable(array $set, string $field): bool;

	/**
	 * Resolve the whole writable set for one case.
	 *
	 * @param array<string, mixed> $action The matched `type: update` action,
	 *                                     carrying its sanitised `citizenWrite`
	 *                                     declaration and its `fields` whitelist.
	 * @param array<string, mixed> $case The citizen's own case row.
	 * @param string $audience The session's audience, e.g. `client`.
	 * @param string $closedField The closed marker the case's collection
	 *                            declares (`closedField`), or ''.
	 *
	 * @return array<string, mixed> The writable set: `fields`, `writable`,
	 *                              `window`, `documents`, `status` and
	 *                              `ended`.
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 * @spec openspec/changes/archive/2026-10-07-citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	public function resolve(array $action, array $case, string $audience, string $closedField = ''): array;

	/**
	 * Whether this case may be withdrawn right now, and onto what.
	 *
	 * The portal keeps no list of withdrawable case types: a type that says
	 * nothing offers nothing, and a window that has closed yields the reason
	 * rather than an action that would fail.
	 *
	 * @param array<string, mixed> $action The matched `type: update` action.
	 * @param array<string, mixed> $case The citizen's own case row.
	 * @param string $closedField The closed marker the case's collection
	 *                            declares (`closedField`), or ''.
	 *
	 * @return array<string, mixed> `declared`, `open`, `reason`,
	 *         `targetStatus` and `confirmText`.
	 *
	 * @spec openspec/specs/withdrawing-your-own-case/spec.md
	 * @spec openspec/changes/archive/2026-10-07-citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	public function withdrawal(array $action, array $case, string $closedField = ''): array;
}//end interface
