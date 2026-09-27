<?php

/**
 * Activity Controller (staff)
 *
 * Staff create, open and close term-long activities, change their
 * supervisors, read the roster and mark attendance
 * (extracurricular-activity-offer). Requires a Nextcloud session, the same
 * posture as `EventController` and `NewsController`.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
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
 * @spec openspec/changes/extracurricular-activity-offer/contract.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActivityAttendanceService;
use OCA\Portaliq\Service\ActivityDraft;
use OCA\Portaliq\Service\ActivityPlaces;
use OCA\Portaliq\Service\ActivitySignupService;
use OCA\Portaliq\Service\ActivityStore;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Staff authoring, roster and attendance for activities.
 *
 * @spec openspec/changes/extracurricular-activity-offer/contract.md
 */
class ActivityController extends Controller {
	/**
	 * HTTP status per attendance refusal.
	 */
	private const ATTENDANCE_STATUS = [
		ActivityAttendanceService::REASON_NOT_FOUND => Http::STATUS_NOT_FOUND,
		ActivityAttendanceService::REASON_UNKNOWN_SESSION => Http::STATUS_UNPROCESSABLE_ENTITY,
		ActivityAttendanceService::REASON_NOT_CONFIRMED => Http::STATUS_UNPROCESSABLE_ENTITY,
		ActivityAttendanceService::REASON_INVALID_STATUS => Http::STATUS_UNPROCESSABLE_ENTITY,
		ActivityAttendanceService::REASON_UNAVAILABLE => Http::STATUS_BAD_GATEWAY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param IUserSession $userSession Confirms a Nextcloud user reached this endpoint.
	 * @param ActivityStore $store The activity rows.
	 * @param ActivityPlaces $places The places arithmetic.
	 * @param ActivitySignupService $signups Supervisors, promotion and roster.
	 * @param ActivityAttendanceService $attendance Attendance marks.
	 * @param ActivityDraft $drafts Validates and sanitises a new activity.
	 */
	public function __construct(
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ActivityStore $store,
		private readonly ActivityPlaces $places,
		private readonly ActivitySignupService $signups,
		private readonly ActivityAttendanceService $attendance,
		private readonly ActivityDraft $drafts,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The staff guard every method calls first, before any read or write
	 * (the same guard as `EventController`).
	 *
	 * @return string The staff member's user id.
	 *
	 * @throws OCSForbiddenException When no Nextcloud user is authenticated.
	 */
	private function requireAuthenticatedStaff(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		return $user->getUID();
	}//end requireAuthenticatedStaff()

	/**
	 * Create a draft activity. The optional fields (`termEnd`,
	 * `signupDeadline`, `sessions`, `supervisorRefs`, `childrenPerSupervisor`,
	 * `waitlistEnabled`, `paymentRequested`, `description`, `location`) are read
	 * from the request body.
	 *
	 * @param string $title The title.
	 * @param string $kind One of club, sport, culture, trip, course, other.
	 * @param array<string, mixed> $target The target (schoolRef/groupRefs/childRefs).
	 * @param string $termStart The first day of the term.
	 * @param int $capacity The most children with a place.
	 *
	 * @return JSONResponse The draft, or 400 / 502.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	#[NoAdminRequired]
	public function create(string $title, string $kind, array $target, string $termStart, int $capacity): JSONResponse {
		$author = $this->requireAuthenticatedStaff();

		$activity = $this->drafts->build(
			required: ['title' => $title, 'kind' => $kind, 'target' => $target, 'termStart' => $termStart, 'capacity' => $capacity],
			params: $this->request->getParams(),
			author: $author
		);
		if ($activity === null) {
			return new JSONResponse(['error' => 'invalid_activity'], Http::STATUS_BAD_REQUEST);
		}

		$saved = $this->store->save(schema: ActivityStore::OFFER, data: $activity);
		if ($saved === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($saved);
	}//end create()

	/**
	 * Open an activity for sign-ups; refused when it has no place.
	 *
	 * @param string $id The activity id or slug.
	 *
	 * @return JSONResponse The activity, or 404 / 422 `no_places` / 502.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	#[NoAdminRequired]
	public function open(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		$activity = $this->store->find(schema: ActivityStore::OFFER, id: $id);
		if ($activity === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->places->placesFor(activity: $activity) < 1) {
			return new JSONResponse(['error' => 'no_places'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return $this->saveStatus(activity: $activity, status: 'open');
	}//end open()

	/**
	 * Close an activity: it stays visible and takes no new sign-ups.
	 *
	 * @param string $id The activity id or slug.
	 *
	 * @return JSONResponse The activity, or 404 / 502.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	#[NoAdminRequired]
	public function close(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		$activity = $this->store->find(schema: ActivityStore::OFFER, id: $id);
		if ($activity === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return $this->saveStatus(activity: $activity, status: 'closed');
	}//end close()

	/**
	 * Replace the supervisors; more places promote from the waiting list.
	 *
	 * @param string $id The activity id or slug.
	 * @param array<int, mixed> $supervisorRefs The new supervisor references.
	 *
	 * @return JSONResponse `{activity, promoted}`, or 404.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	#[NoAdminRequired]
	public function supervisors(string $id, array $supervisorRefs = []): JSONResponse {
		$this->requireAuthenticatedStaff();

		$result = $this->signups->setSupervisors(activityId: $id, supervisorRefs: $supervisorRefs);
		if ($result === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($result);
	}//end supervisors()

	/**
	 * The roster: places, who holds one, and the waiting list in order.
	 *
	 * @param string $id The activity id or slug.
	 *
	 * @return JSONResponse `{places, confirmed, waitlist}`, or 404.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	#[NoAdminRequired]
	public function roster(string $id): JSONResponse {
		$this->requireAuthenticatedStaff();

		$roster = $this->signups->roster(activityId: $id);
		if ($roster === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($roster);
	}//end roster()

	/**
	 * Mark one child for one session.
	 *
	 * @param string $id The activity id or slug.
	 * @param string $sessionId The session id.
	 * @param string $childRef The child.
	 * @param string $status One of present, absent, excused.
	 *
	 * @return JSONResponse The attendance row, or 404 / 422 / 502.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-mark-attendance-per-session
	 */
	#[NoAdminRequired]
	public function attendance(string $id, string $sessionId, string $childRef, string $status): JSONResponse {
		$staff = $this->requireAuthenticatedStaff();

		$result = $this->attendance->mark(activityId: $id, sessionId: $sessionId, childRef: $childRef, status: $status, markedByRef: $staff);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], self::ATTENDANCE_STATUS[$result['error']]);
		}

		return new JSONResponse($result['attendance'] ?? []);
	}//end attendance()

	/**
	 * Save a new status on an activity.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 * @param string $status The new status.
	 *
	 * @return JSONResponse The saved activity, or 502.
	 */
	private function saveStatus(array $activity, string $status): JSONResponse {
		$saved = $this->store->save(
			schema: ActivityStore::OFFER,
			data: array_merge($activity, ['status' => $status]),
			id: $this->store->idOf(row: $activity)
		);
		if ($saved === null) {
			return new JSONResponse(['error' => 'write_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse($saved);
	}//end saveStatus()
}//end class
