<?php

/**
 * Contract of ActivityAttendanceService
 *
 * The public surface of ActivityAttendanceService that other components call, so they depend
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
 * Contract of ActivityAttendanceService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityAttendanceServiceInterface {

	/**
	 * Refusal: the activity does not exist (404).
	 */
	public const REASON_NOT_FOUND = 'not_found';

	/**
	 * Refusal: the session is not one of the activity's (422).
	 */
	public const REASON_UNKNOWN_SESSION = 'unknown_session';

	/**
	 * Refusal: the child has no place (422).
	 */
	public const REASON_NOT_CONFIRMED = 'not_confirmed';

	/**
	 * Refusal: the status is not one of present, absent, excused (422).
	 */
	public const REASON_INVALID_STATUS = 'invalid_status';

	/**
	 * Refusal: the rows could not be read or written (502).
	 */
	public const REASON_UNAVAILABLE = 'unavailable';

	/**
	 * Mark one child for one session; a second mark updates the first.
	 *
	 * @param string $activityId The activity id or slug.
	 * @param string $sessionId The session id.
	 * @param string $childRef The child.
	 * @param string $status One of present, absent, excused.
	 * @param string $markedByRef The staff member marking.
	 *
	 * @return array{attendance?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	public function mark(string $activityId, string $sessionId, string $childRef, string $status, string $markedByRef): array;
}//end interface
