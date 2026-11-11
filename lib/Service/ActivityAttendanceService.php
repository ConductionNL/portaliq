<?php

/**
 * Activity Attendance Service
 *
 * Attendance per session for term-long activities
 * (extracurricular-activity-offer). Staff mark a child present, absent or
 * excused for one of the activity's declared sessions; only a child with a
 * place can be marked, and a second mark for the same session and child
 * updates the first rather than adding a row.
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
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Marks attendance for one session and one child.
 *
 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
 */
class ActivityAttendanceService {
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
	 * The statuses a mark may carry.
	 */
	private const STATUSES = ['present', 'absent', 'excused'];

	/**
	 * Constructor.
	 *
	 * @param ActivityStore $store The activity rows.
	 * @param ActivityPlaces $places Who holds a place.
	 * @param ITimeFactory $time The clock for `markedAt`.
	 */
	public function __construct(
		private readonly ActivityStore $store,
		private readonly ActivityPlaces $places,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	public function mark(string $activityId, string $sessionId, string $childRef, string $status, string $markedByRef): array {
		return $this->record(
			mark: new AttendanceMark(activityId: $activityId, sessionId: $sessionId, childRef: $childRef, status: $status, markedByRef: $markedByRef)
		);
	}//end mark()

	/**
	 * Write one mark, over the existing one for the same session and child.
	 *
	 * @param AttendanceMark $mark The mark.
	 *
	 * @return array{attendance?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	private function record(AttendanceMark $mark): array {
		$activity = $this->store->lookup(schema: ActivityStore::OFFER, id: $mark->activityId);
		if ($activity === null) {
			return ['error' => self::REASON_NOT_FOUND];
		}

		$refusal = $this->refusal(activity: $activity, mark: $mark);
		if ($refusal !== null) {
			return ['error' => $refusal];
		}

		$existing = $this->existingMark(activity: $activity, mark: $mark);
		if ($existing === false) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		return $this->saveOver(existing: $existing, data: $this->markData(activity: $activity, mark: $mark));
	}//end record()

	/**
	 * The stored shape of a mark, stamped now.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param AttendanceMark       $mark     The mark.
	 *
	 * @return array<string, mixed>
	 */
	private function markData(array $activity, AttendanceMark $mark): array {
		return [
			'activityRef' => $this->store->idOf(row: $activity),
			'sessionId' => $mark->sessionId,
			'childRef' => $mark->childRef,
			'status' => $mark->status,
			'markedByRef' => $mark->markedByRef,
			'markedAt' => gmdate('c', $this->time->getTime()),
		];
	}//end markData()

	/**
	 * Save a mark over the existing one, or as a new one.
	 *
	 * @param array<string, mixed>|null $existing The existing mark, or null.
	 * @param array<string, mixed>      $data     The mark's stored shape.
	 *
	 * @return array{attendance?: array<string, mixed>, error?: string}
	 */
	private function saveOver(?array $existing, array $data): array {
		$id = '';
		if ($existing !== null) {
			$data = array_merge($existing, $data);
			$id = $this->store->idOf(row: $existing);
		}

		$saved = $this->store->save(schema: ActivityStore::ATTENDANCE, data: $data, id: $id);
		if ($saved === null) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		return ['attendance' => $saved];
	}//end saveOver()

	/**
	 * Why this mark may not be written, or null when it may.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param AttendanceMark       $mark     The mark.
	 *
	 * @return string|null
	 */
	private function refusal(array $activity, AttendanceMark $mark): ?string {
		if (in_array($mark->status, self::STATUSES, true) === false) {
			return self::REASON_INVALID_STATUS;
		}

		if ($this->hasSession(activity: $activity, sessionId: $mark->sessionId) === false) {
			return self::REASON_UNKNOWN_SESSION;
		}

		return $this->signupRefusal(activity: $activity, childRef: $mark->childRef);
	}//end refusal()

	/**
	 * Why the child holds no confirmed place on the activity, or null when
	 * they do.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param string               $childRef The child.
	 *
	 * @return string|null
	 */
	private function signupRefusal(array $activity, string $childRef): ?string {
		$signups = $this->store->rows(schema: ActivityStore::SIGNUP);
		if ($signups === null) {
			return self::REASON_UNAVAILABLE;
		}

		$own = $this->places->forActivity(signups: $signups, activityKeys: $this->store->keys(row: $activity));
		$signup = $this->places->activeFor(signups: $own, childRef: $childRef);
		if ($signup === null || $signup['status'] !== ActivityPlaces::CONFIRMED) {
			return self::REASON_NOT_CONFIRMED;
		}

		return null;
	}//end signupRefusal()

	/**
	 * Whether the session is one the activity declares.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param string $sessionId The session id.
	 *
	 * @return bool
	 */
	private function hasSession(array $activity, string $sessionId): bool {
		if ($sessionId === '') {
			return false;
		}

		foreach ((array)($activity['sessions'] ?? []) as $session) {
			if (is_array($session) === true && ($session['id'] ?? null) === $sessionId) {
				return true;
			}
		}

		return false;
	}//end hasSession()

	/**
	 * The existing mark for this activity, session and child; null when there
	 * is none, false when the rows could not be read.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param AttendanceMark       $mark     The mark.
	 *
	 * @return array<string, mixed>|false|null
	 */
	private function existingMark(array $activity, AttendanceMark $mark): array|false|null {
		$rows = $this->store->rows(schema: ActivityStore::ATTENDANCE);
		if ($rows === null) {
			return false;
		}

		$keys = $this->store->keys(row: $activity);
		foreach ($rows as $row) {
			if (in_array((string)($row['activityRef'] ?? ''), $keys, true) === true
				&& (string)($row['sessionId'] ?? '') === $mark->sessionId
				&& (string)($row['childRef'] ?? '') === $mark->childRef
			) {
				return $row;
			}
		}

		return null;
	}//end existingMark()
}//end class
