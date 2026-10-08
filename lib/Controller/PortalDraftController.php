<?php

/**
 * Portaliq Portal Draft Controller
 *
 * `GET`, `PUT` and `DELETE /portal/api/drafts/{appId}/{actionId}`: the
 * resident's own saved answers of a create or endpoint action. The subject
 * comes from the bearer only; a request carries no subject, and a draft is
 * never forwarded to the contributing app.
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
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Intake\PortalDraftStore;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Save, read and delete the bearer's draft of an action.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 */
class PortalDraftController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalDraftStore $drafts The draft store.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalDraftStore $drafts,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The bearer's saved draft of an action.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse 401 without a session, 404 without a live draft.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $appId, string $actionId): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef === '') {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$draft = $this->drafts->read(subjectRef: $subjectRef, actionKey: $appId . '/' . $actionId);
		if ($draft === null) {
			return new JSONResponse(['found' => false], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($draft);
	}//end show()

	/**
	 * Save the bearer's draft of an action.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 * @param array<string, mixed> $answers The visible answers.
	 * @param string $step The step reached.
	 * @param int $retentionDays The action's declared retention, 1 to 90.
	 *
	 * @return JSONResponse 401 without a session, 500 when nothing could be stored.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function save(string $appId, string $actionId, array $answers = [], string $step = '', int $retentionDays = 30): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef === '') {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$saved = $this->drafts->save(
			subjectRef: $subjectRef,
			actionKey: $appId . '/' . $actionId,
			answers: $answers,
			step: $step,
			retentionDays: $retentionDays
		);
		if ($saved === null) {
			return new JSONResponse(['saved' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($saved);
	}//end save()

	/**
	 * Delete the bearer's draft of an action, once the action was sent.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse 401 without a session.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function discard(string $appId, string $actionId): JSONResponse {
		$subjectRef = $this->subjectRef();
		if ($subjectRef === '') {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$this->drafts->discard(subjectRef: $subjectRef, actionKey: $appId . '/' . $actionId);
		return new JSONResponse(['deleted' => true]);
	}//end discard()

	/**
	 * The bearer's subject reference, or '' for a visitor who is not signed in.
	 *
	 * @return string
	 */
	private function subjectRef(): string {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		return (string)($subject['subjectRef'] ?? '');
	}//end subjectRef()
}//end class
