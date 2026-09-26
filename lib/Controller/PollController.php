<?php

/**
 * Portaliq Poll Controller
 *
 * A staff member creates a poll; a portal subject lists and answers their
 * own (parent-polls, learniq round-1 finding 9.9).
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
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PollService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Creates polls (staff) and serves a subject's own (portal).
 *
 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md
 */
class PollController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PollService $polls Creates, lists and answers polls.
	 * @param PortalSessionService $session Resolves a portal subject.
	 * @param IUserSession $userSession The staff user, when there is one.
	 */
	public function __construct(
		IRequest $request,
		private readonly PollService $polls,
		private readonly PortalSessionService $session,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a poll, as the calling staff member.
	 *
	 * @param string $question What is being asked.
	 * @param array<int, array<string, string>> $options Each `{id, label}`.
	 * @param string $audience Which portal audience this poll addresses.
	 * @param string $organisation The tenant this poll is addressed within.
	 * @param string $closesAt ISO 8601, or '' for no close date.
	 *
	 * @return JSONResponse The created poll, or a refusal.
	 *
	 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-poll-is-created-by-staff-addressed-to-one-audience-and-organisation
	 */
	#[NoAdminRequired]
	public function create(
		string $question,
		array $options = [],
		string $audience = '',
		string $organisation = '',
		string $closesAt = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->polls->create(
			question: $question,
			options: $options,
			audience: $audience,
			organisation: $organisation,
			closesAt: $closesAt,
			createdBy: $user->getUID()
		);
		if (isset($result['error']) === true) {
			return new JSONResponse($result, $this->createStatusFor(error: (string)$result['error']));
		}

		return new JSONResponse($result);
	}//end create()

	/**
	 * The HTTP status a `create()` refusal answers with.
	 *
	 * @param string $error The error code.
	 *
	 * @return int
	 */
	private function createStatusFor(string $error): int {
		if ($error === 'needs_two_options') {
			return Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		return Http::STATUS_BAD_REQUEST;
	}//end createStatusFor()

	/**
	 * The bearer's own polls: their own audience and organisation, each
	 * carrying only their own response.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-portal-subject-sees-only-polls-for-their-own-audience-and-organisation
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->resolveSubject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['polls' => $this->polls->forSubject(subject: $subject)]);
	}//end index()

	/**
	 * Answer one poll, as the bearer's own subject.
	 *
	 * @param string $id The poll id.
	 * @param string $optionId The chosen option.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/parent-polls/specs/parent-polls/spec.md#requirement-a-subject-may-answer-once-and-change-their-answer-while-the-poll-is-open
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function respond(string $id, string $optionId): JSONResponse {
		$subject = $this->resolveSubject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$result = $this->polls->respond(pollId: $id, optionId: $optionId, subject: $subject);
		if (isset($result['error']) === true) {
			return new JSONResponse($result, $this->statusFor(error: (string)$result['error']));
		}

		return new JSONResponse($result);
	}//end respond()

	/**
	 * Resolve the portal subject from the bearer.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveSubject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end resolveSubject()

	/**
	 * The HTTP status a `respond()` refusal answers with.
	 *
	 * @param string $error The error code.
	 *
	 * @return int
	 */
	private function statusFor(string $error): int {
		if ($error === 'not_found') {
			return Http::STATUS_NOT_FOUND;
		}

		if ($error === 'unknown_option') {
			return Http::STATUS_UNPROCESSABLE_ENTITY;
		}

		if ($error === 'closed') {
			return Http::STATUS_FORBIDDEN;
		}

		return Http::STATUS_BAD_REQUEST;
	}//end statusFor()
}//end class
