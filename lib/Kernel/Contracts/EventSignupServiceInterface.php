<?php

/**
 * Contract of EventSignupService
 *
 * The public surface of EventSignupService that other components call, so they depend
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
 * Contract of EventSignupService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EventSignupServiceInterface {

	public const REASON_ROLE_NOT_FOUND = 'role-not-found';

	public const REASON_ROLE_FULL = 'role-full';

	/**
	 * Attempt a sign-up. Returns null on success; a reason string on refusal
	 * — the event/role not being reachable by this guardian, or a child that
	 * is not the guardian's own, is {@see self::REASON_ROLE_NOT_FOUND} (the controller maps that to 404,
	 * no existence oracle), a full role is {@see self::REASON_ROLE_FULL}
	 * (422). Nothing is written on ANY refusal path.
	 *
	 * @param string $subjectRef The guardian's own subjectRef.
	 * @param string $eventId The event id.
	 * @param string $roleId The signup role id.
	 * @param string $childRef The child the sign-up is for (may be empty for an adult-only role).
	 * @param string $note An optional note (e.g. "brings cups").
	 *
	 * @return string|null The refusal reason, or null on success.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side
	 */
	public function attemptSignup(string $subjectRef, string $eventId, string $roleId, string $childRef = '', string $note = ''): ?string;
}//end interface
