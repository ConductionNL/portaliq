<?php

/**
 * Activity Guardian Controller
 *
 * The guardian side of term-long activities (extracurricular-activity-offer):
 * read the activities in reach, sign one of their own children up, withdraw.
 * Portal bearer, never a Nextcloud session; the subject comes from the bearer,
 * never from a client parameter.
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
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\ActivityFeedReader;
use OCA\Portaliq\Service\ActivitySignupService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Guardian read, sign-up and withdraw for activities.
 *
 * @spec openspec/changes/extracurricular-activity-offer/contract.md
 */
class ActivityGuardianController extends Controller implements PortalProtected {
	/**
	 * HTTP status per sign-up refusal.
	 */
	private const REFUSAL_STATUS = [
		ActivitySignupService::REASON_NOT_FOUND => Http::STATUS_NOT_FOUND,
		ActivitySignupService::REASON_CLOSED => Http::STATUS_UNPROCESSABLE_ENTITY,
		ActivitySignupService::REASON_FULL => Http::STATUS_UNPROCESSABLE_ENTITY,
		ActivitySignupService::REASON_DUPLICATE => Http::STATUS_CONFLICT,
		ActivitySignupService::REASON_UNAVAILABLE => Http::STATUS_BAD_GATEWAY,
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param ActivityFeedReader $feedReader The guardian-scoped read.
	 * @param ActivitySignupService $signups Sign-up and withdraw.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly ActivityFeedReader $feedReader,
		private readonly ActivitySignupService $signups,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every activity in the calling guardian's reach, with the places left and
	 * their own children's sign-ups.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function feed(): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef === '') {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse($this->feedReader->feedFor(subjectRef: $subjectRef));
	}//end feed()

	/**
	 * Sign one of the guardian's own children up.
	 *
	 * @param string $id The activity id or slug.
	 * @param string $childRef The child.
	 * @param string $note An optional note for the supervisor.
	 *
	 * @return JSONResponse `{status, position?}`, or 401 / 404 / 409 / 422 / 502.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-guardian-must-be-able-to-sign-up-one-of-their-own-children-with-a-waiting-list-when-full
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function signup(string $id, string $childRef, string $note = ''): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef === '') {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->signups->signUp(subjectRef: $subjectRef, activityId: $id, childRef: $childRef, note: $note);
		if (isset($result['error']) === true) {
			return new JSONResponse(['error' => $result['error']], self::REFUSAL_STATUS[$result['error']]);
		}

		return new JSONResponse($result);
	}//end signup()

	/**
	 * Withdraw a child's sign-up.
	 *
	 * @param string $id The activity id or slug.
	 * @param string $childRef The child.
	 *
	 * @return JSONResponse 204, or 401 / 404 / 502.
	 *
	 * @spec openspec/changes/extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function withdraw(string $id, string $childRef): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef === '') {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$reason = $this->signups->withdraw(subjectRef: $subjectRef, activityId: $id, childRef: $childRef);
		if ($reason !== null) {
			return new JSONResponse(['error' => $reason], self::REFUSAL_STATUS[$reason]);
		}

		return new JSONResponse([], Http::STATUS_NO_CONTENT);
	}//end withdraw()

	/**
	 * The guardian's subjectRef from the bearer, or '' (fail closed).
	 *
	 * @return string
	 */
	private function subjectRef(): string {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		return (string)($subject['subjectRef'] ?? '');
	}//end subjectRef()
}//end class
