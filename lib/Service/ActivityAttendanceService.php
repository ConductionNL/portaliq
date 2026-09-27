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
		$activity = $this->store->lookup(schema: ActivityStore::OFFER, id: $activityId);
		if ($activity === null) {
			return ['error' => self::REASON_NOT_FOUND];
		}

		$refusal = $this->refusal(activity: $activity, sessionId: $sessionId, childRef: $childRef, status: $status);
		if ($refusal !== null) {
			return ['error' => $refusal];
		}

		$existing = $this->existingMark(activity: $activity, sessionId: $sessionId, childRef: $childRef);
		if ($existing === false) {
			return ['error' => self::REASON_UNAVAILABLE];
		}

		$data = [
			'activityRef' => $this->store->idOf(row: $activity),
			'sessionId' => $sessionId,
			'childRef' => $childRef,
			'status' => $status,
			'markedByRef' => $markedByRef,
			'markedAt' => gmdate('c', $this->time->getTime()),
		];
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
	}//end mark()

	/**
	 * Why this mark may not be written, or null when it may.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param string $sessionId The session id.
	 * @param string $childRef The child.
	 * @param string $status The status.
	 *
	 * @return string|null
	 */
	private function refusal(array $activity, string $sessionId, string $childRef, string $status): ?string {
		if (in_array($status, self::STATUSES, true) === false) {
			return self::REASON_INVALID_STATUS;
		}

		if ($this->hasSession(activity: $activity, sessionId: $sessionId) === false) {
			return self::REASON_UNKNOWN_SESSION;
		}

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
	}//end refusal()

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
	 * @param string $sessionId The session id.
	 * @param string $childRef The child.
	 *
	 * @return array<string, mixed>|false|null
	 */
	private function existingMark(array $activity, string $sessionId, string $childRef): array|false|null {
		$rows = $this->store->rows(schema: ActivityStore::ATTENDANCE);
		if ($rows === null) {
			return false;
		}

		$keys = $this->store->keys(row: $activity);
		foreach ($rows as $row) {
			if (in_array((string)($row['activityRef'] ?? ''), $keys, true) === true
				&& (string)($row['sessionId'] ?? '') === $sessionId
				&& (string)($row['childRef'] ?? '') === $childRef
			) {
				return $row;
			}
		}

		return null;
	}//end existingMark()
}//end class
