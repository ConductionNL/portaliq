<?php

/**
 * Event Guardian Controller
 *
 * The guardian-facing side of `events-and-signups`: the event feed, RSVP,
 * and sign-up — all scoped to the CALLING guardian's own resolved audience.
 * Guarded by `PortalAuthMiddleware` via the `PortalProtected` marker
 * (fail-closed 401 without a valid bearer); the subject is read from the
 * validated bearer, never from a client parameter.
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
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\EventFeedReader;
use OCA\Portaliq\Service\EventRsvpService;
use OCA\Portaliq\Service\EventSignupService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Guardian read/RSVP/signup path for events.
 *
 * @spec openspec/changes/events-and-signups/design.md#api-design
 */
class EventGuardianController extends Controller implements PortalProtected {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param EventFeedReader $feedReader The guardian-scoped read path.
	 * @param EventRsvpService $rsvpService Upserts an RSVP.
	 * @param EventSignupService $signupService Capacity-checked sign-up.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly EventFeedReader $feedReader,
		private readonly EventRsvpService $rsvpService,
		private readonly EventSignupService $signupService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every published event in the calling guardian's own audience.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function feed(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->feedReader->feedFor(subjectRef: (string)($subject['subjectRef'] ?? '')));
	}//end feed()

	/**
	 * Upsert an RSVP for one of the guardian's own children.
	 *
	 * @param string $id The event id.
	 * @param string $childRef The child.
	 * @param string $response One of `yes`, `no`, `maybe`.
	 *
	 * @return JSONResponse 204 on success, 404 otherwise.
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function rsvp(string $id, string $childRef, string $response): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$recorded = $this->rsvpService->rsvp(subjectRef: (string)($subject['subjectRef'] ?? ''), eventId: $id, childRef: $childRef, response: $response);
		if ($recorded === false) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end rsvp()

	/**
	 * Sign up for a volunteer/material role.
	 *
	 * @param string $id The event id.
	 * @param string $roleId The role id.
	 * @param string $childRef Optional child reference.
	 * @param string $note Optional note.
	 *
	 * @return JSONResponse 204 on success, 404 (role/event unreachable) or 422 (role full).
	 *
	 * @spec openspec/changes/events-and-signups/specs/portaliq-cms/spec.md#requirement-a-sign-up-role-enforces-its-capacity-server-side
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function signup(string $id, string $roleId, string $childRef = '', string $note = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$reason = $this->signupService->attemptSignup(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			eventId: $id,
			roleId: $roleId,
			childRef: $childRef,
			note: $note
		);

		if ($reason === EventSignupService::REASON_ROLE_NOT_FOUND) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($reason === EventSignupService::REASON_ROLE_FULL) {
			return new JSONResponse(['error' => 'role-full'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end signup()

	/**
	 * Resolve the subject from the bearer (fail-closed).
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
