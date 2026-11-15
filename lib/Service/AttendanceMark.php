<?php

/**
 * Portaliq Attendance Mark
 *
 * One attendance mark a staff member sets: which activity and session,
 * which child, the status, and who marked it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The mark ActivityAttendanceService::mark() is asked to write.
 *
 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
 */
final class AttendanceMark {
	/**
	 * Constructor.
	 *
	 * @param string $activityId  The activity id or slug.
	 * @param string $sessionId   The session id.
	 * @param string $childRef    The child.
	 * @param string $status      One of present, absent, excused.
	 * @param string $markedByRef The staff member marking.
	 */
	public function __construct(
		public readonly string $activityId,
		public readonly string $sessionId,
		public readonly string $childRef,
		public readonly string $status,
		public readonly string $markedByRef,
	) {
	}//end __construct()
}//end class
