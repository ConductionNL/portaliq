<?php

/**
 * Portaliq Portal Draft Controller
 *
 * `GET`, `PUT` and `DELETE /portal/api/drafts/{appId}/{actionId}`: the
 * resident's own saved answers of a create action. The draft is the object
 * that action creates, in OpenRegister status `draft` (decision 180), owned
 * by `portal:<subjectRef>` (decision 195); portaliq keeps no draft store of
 * its own. The subject comes from the bearer only; a request carries no
 * subject. A draft past the action's retention reads as none (decision 201).
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
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use DateTimeImmutable;
use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\Intake\DestinationSubmit;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Save, read and discard the bearer's draft of a create action.
 *
 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
 */
class PortalDraftController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest             $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param ActionDraft          $actions Resolves the action, its draft body and its retention.
	 * @param DestinationSubmit    $drafts  Saves and discards drafts in OpenRegister.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly ActionDraft $actions,
		private readonly DestinationSubmit $drafts,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The bearer's live draft of an action.
	 *
	 * @param string $appId    The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse 401 without a session; 404 for an unknown action, no draft, or one past its retention.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function show(string $appId, string $actionId): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->actions->actionFor(subject: $subject, appId: $appId, actionId: $actionId);
		$draft = null;
		if ($match !== null) {
			$draft = $this->actions->find(action: $match['action'], subjectRef: (string)$subject['subjectRef']);
		}

		if ($match === null || $draft === null) {
			return new JSONResponse(['found' => false], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(
			[
				'id' => (string)$draft['id'],
				'status' => 'draft',
				'step' => '',
				'expiresAt' => (string)$draft['expiresAt'],
				'answers' => ActionDraft::answersOf(action: $match['action'], draft: $draft),
			]
		);
	}//end show()

	/**
	 * Save the bearer's draft of an action: start it, or update the one there is.
	 *
	 * A missing required answer is allowed; a type-invalid one answers 422
	 * with the message on its field, and nothing is saved.
	 *
	 * @param string               $appId    The contributing app.
	 * @param string               $actionId The action.
	 * @param array<string, mixed> $answers  The visible answers.
	 *
	 * @return JSONResponse 401 without a session, 404 for an unknown action, 403 when the
	 *                      scope claim does not resolve, 422 with `errors`, 503 when OpenRegister cannot be reached.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function save(string $appId, string $actionId, array $answers = []): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match = $this->actions->actionFor(subject: $subject, appId: $appId, actionId: $actionId);
		if ($match === null) {
			return new JSONResponse(['found' => false], Http::STATUS_NOT_FOUND);
		}

		$body = $this->actions->bodyOf(match: $match, subject: $subject, answers: $answers);
		if ($body === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$subjectRef = (string)$subject['subjectRef'];
		$existing   = $this->actions->find(action: $match['action'], subjectRef: $subjectRef);
		$saved      = $this->drafts->saveDraft(
			destination: ActionDraft::destinationOf(action: $match['action']),
			payload: $body,
			subjectRef: $subjectRef,
			draftId: (string)($existing['id'] ?? ''),
			scope: $appId . '/' . $actionId
		);
		if ($saved['ok'] === false) {
			return new JSONResponse(
				['saved' => false, 'error' => 'invalid', 'errors' => ActionDraft::errorsOf(findings: $saved['findings'])],
				$saved['status']
			);
		}

		$expiresAt = (new DateTimeImmutable())->modify('+' . ActionDraft::retentionDays(action: $match['action']) . ' days');

		return new JSONResponse(
			[
				'id' => (string)$saved['answer']['id'],
				'status' => 'draft',
				'step' => '',
				'expiresAt' => $expiresAt->format(DATE_ATOM),
				'answers' => ActionDraft::answersOf(action: $match['action'], draft: ['data' => $body]),
			]
		);
	}//end save()

	/**
	 * Discard the bearer's draft of an action.
	 *
	 * @param string $appId    The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse 401 without a session; `deleted` says whether a draft was removed.
	 *
	 * @spec openspec/changes/site-multi-step-forms/tasks.md#T8
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function discard(string $appId, string $actionId): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$match   = $this->actions->actionFor(subject: $subject, appId: $appId, actionId: $actionId);
		$deleted = false;
		if ($match !== null) {
			$subjectRef = (string)$subject['subjectRef'];
			$draft      = $this->actions->find(action: $match['action'], subjectRef: $subjectRef);
			if ($draft !== null) {
				$deleted = $this->drafts->discardDraft(
					destination: ActionDraft::destinationOf(action: $match['action']),
					subjectRef: $subjectRef,
					draftId: (string)$draft['id']
				);
			}
		}

		return new JSONResponse(['deleted' => $deleted]);
	}//end discard()

	/**
	 * The bearer's subject, or null for a visitor who is not signed in.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if (is_array($subject) === false || (string)($subject['subjectRef'] ?? '') === '') {
			return null;
		}

		return $subject;
	}//end subject()
}//end class
