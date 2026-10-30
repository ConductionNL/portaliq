<?php

/**
 * Portaliq Portal Actions Controller
 *
 * Mijn acties: the resident's own actions, and the dialog's calls for an
 * action in a plan.
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
 * @spec openspec/changes/personal-action-list/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Actions\PortalActionService;
use OCA\Portaliq\Service\Plans\PortalPlanService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

/**
 * Every route answers only for an action the bearer owns, is assigned to or
 * shares a plan on; an unknown action and someone else's answer the same 404.
 * The service decides who may change what.
 *
 * @spec openspec/changes/personal-action-list/specs/resident-actions/spec.md
 */
class PortalActionsController extends Controller implements PortalProtected {

	/**
	 * The fields a request may send for an action.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['title', 'description', 'kind', 'status', 'endDate', 'assignee', 'file'];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalActionService $actions The action rules.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalActionService $actions,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The resident's actions with the count done.
	 *
	 * @return JSONResponse `{actions, done, total, assignees}`, or 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return new JSONResponse($this->actions->overview(subject: $subject));
	}//end index()

	/**
	 * Add an action to the resident's own list.
	 *
	 * @return JSONResponse `{id}` with 201, 400, or 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		$result = $this->actions->create(subject: $subject, data: $this->sent());
		if ($result['status'] !== PortalPlanService::OK) {
			return $this->answer(result: $result['status']);
		}

		return new JSONResponse(['id' => $result['id']], Http::STATUS_CREATED);
	}//end create()

	/**
	 * Change an action.
	 *
	 * @param string $id The action.
	 * @param string $plan The plan it is in, for a participant.
	 *
	 * @return JSONResponse `{ok: true}`, or 400, 403, 404, 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function update(string $id, string $plan=''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->actions->update(subject: $subject, id: $id, data: $this->sent(), planId: $plan));
	}//end update()

	/**
	 * Delete an action.
	 *
	 * @param string $id The action.
	 * @param string $plan The plan it is in, for a participant.
	 *
	 * @return JSONResponse `{ok: true}`, or 403 for an assignee, 404, 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function destroy(string $id, string $plan=''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(result: $this->actions->delete(subject: $subject, id: $id, planId: $plan));
	}//end destroy()

	/**
	 * The history of an action.
	 *
	 * @param string $id The action.
	 * @param string $plan The plan it is in, for a participant.
	 *
	 * @return JSONResponse `{history}`, or 404, 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function history(string $id, string $plan=''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		$lines = $this->actions->history(subject: $subject, id: $id, planId: $plan);
		if ($lines === null) {
			return $this->answer(result: PortalPlanService::NOT_FOUND);
		}

		return new JSONResponse(['history' => $lines]);
	}//end history()

	/**
	 * Keep one file with an action (multipart `file`).
	 *
	 * @param string $id The action.
	 * @param string $plan The plan it is in, for a participant.
	 *
	 * @return JSONResponse `{ok: true}`, or 400 for a file that is not a PDF, JPG or PNG up to 10 MB, 403, 404, 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function attach(string $id, string $plan=''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return $this->answer(
			result: $this->actions->attach(subject: $subject, id: $id, uploaded: $this->request->getUploadedFile('file'), planId: $plan)
		);
	}//end attach()

	/**
	 * Open the action's file.
	 *
	 * @param string $id The action.
	 * @param string $plan The plan it is in, for a participant.
	 *
	 * @return Response The file, or 404, 401.
	 *
	 * @spec openspec/changes/personal-action-list/tasks.md#t03
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function file(string $id, string $plan=''): Response {
		$subject = $this->subject();
		if ($subject === null) {
			return $this->unauthenticated();
		}

		return ($this->actions->file(subject: $subject, id: $id, planId: $plan) ?? $this->answer(result: PortalPlanService::NOT_FOUND));
	}//end file()

	/**
	 * The answer for a service outcome.
	 *
	 * @param string $result One of the PortalPlanService outcomes.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $result): JSONResponse {
		$status = match ($result) {
			PortalPlanService::OK => Http::STATUS_OK,
			PortalPlanService::NOT_FOUND => Http::STATUS_NOT_FOUND,
			PortalPlanService::FORBIDDEN => Http::STATUS_FORBIDDEN,
			PortalPlanService::INVALID => Http::STATUS_BAD_REQUEST,
			default => Http::STATUS_INTERNAL_SERVER_ERROR,
		};
		if ($status === Http::STATUS_OK) {
			return new JSONResponse(['ok' => true]);
		}

		return new JSONResponse(['error' => $result], $status);
	}//end answer()

	/**
	 * The action fields of the request, nothing else.
	 *
	 * @return array<string, mixed>
	 */
	private function sent(): array {
		return array_intersect_key($this->request->getParams(), array_flip(self::FIELDS));
	}//end sent()

	/**
	 * The 401 answer.
	 *
	 * @return JSONResponse
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
	}//end unauthenticated()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null || (string)($subject['subjectRef'] ?? '') === '') {
			return null;
		}

		return $subject;
	}//end subject()
}//end class
