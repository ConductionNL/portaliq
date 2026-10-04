<?php

/**
 * Portaliq Portal Draft Controller (site-multi-step-forms, REQ-SMF-021)
 *
 * The three routes behind "Opslaan en later verdergaan": read back the
 * resident's own draft of one action, save it, and throw it away. Every route
 * needs a portal session; a signed-out visitor gets no draft at all, because
 * there is nobody to keep it for.
 *
 * Only an action that declares `draft` may have one, so a page cannot quietly
 * start storing answers for an action whose app never asked for it. The route
 * never takes a draft id: the store finds the subject's own draft under their
 * own scope (PortalDraftStore).
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
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\PortalDraftStore;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * The resident's own drafts of a form in steps.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
 */
class PortalDraftController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalContributionRegistry $registry The subject's own manifest.
	 * @param PortalSessionService $session Resolves the bearer.
	 * @param PortalDraftStore $drafts Reads, writes and discards a draft.
	 * @param ITimeFactory $time Testable clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalContributionRegistry $registry,
		private readonly PortalSessionService $session,
		private readonly PortalDraftStore $drafts,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The resident's own draft of one action, or 404 when there is none.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse The draft, or 401 / 403 / 404.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function show(string $appId, string $actionId): JSONResponse {
		$context = $this->context(appId: $appId, actionId: $actionId);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$draft = $this->drafts->mine(
			subject: $context['subject'],
			app: $appId,
			actionId: $actionId,
			now: $this->now()
		);
		if ($draft === null) {
			return new JSONResponse(['error' => 'no_draft'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['draft' => $this->served(draft: $draft)]);
	}//end show()

	/**
	 * Save what the resident has typed so far. Only the action's own fields
	 * are kept, and never a file field: the answers are re-whitelisted here
	 * exactly as a submit would be.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse The stored draft, or 401 / 403 / 502.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function save(string $appId, string $actionId): JSONResponse {
		$context = $this->context(appId: $appId, actionId: $actionId);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$now   = $this->now();
		$draft = $this->drafts->save(
			subject: $context['subject'],
			app: $appId,
			actionId: $actionId,
			answers: $this->answers(action: $context['action']),
			step: (int)$this->request->getParam('step', 0),
			expiresAt: $this->expiry(action: $context['action']),
			now: $now
		);
		if ($draft === null) {
			return new JSONResponse(['error' => 'draft_not_saved'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['draft' => $this->served(draft: $draft)]);
	}//end save()

	/**
	 * Throw the resident's own draft away.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return JSONResponse Whether one was there, or 401 / 403.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function destroy(string $appId, string $actionId): JSONResponse {
		$context = $this->context(appId: $appId, actionId: $actionId);
		if ($context instanceof JSONResponse) {
			return $context;
		}

		$gone = $this->drafts->discard(
			subject: $context['subject'],
			app: $appId,
			actionId: $actionId,
			now: $this->now()
		);

		return new JSONResponse(['discarded' => $gone]);
	}//end destroy()

	/**
	 * The session and the action behind a request, or the refusal: 401 without
	 * a session, 403 when the subject's own manifest holds no such action or
	 * that action declares no `draft`.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return array{subject: array<string, mixed>, action: array<string, mixed>}|JSONResponse
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	private function context(string $appId, string $actionId): array|JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$action = $this->draftingAction(subject: $subject, appId: $appId, actionId: $actionId);
		if ($action === null) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return ['subject' => $subject, 'action' => $action];
	}//end context()

	/**
	 * The action in the subject's OWN aggregated manifest that declares a
	 * `draft`, or null.
	 *
	 * @param array<string, mixed> $subject The resolved subject.
	 * @param string $appId The contributing app.
	 * @param string $actionId The action.
	 *
	 * @return array<string, mixed>|null The action.
	 */
	private function draftingAction(array $subject, string $appId, string $actionId): ?array {
		$aggregate = $this->registry->aggregateFor($subject);
		foreach (($aggregate['contributions'] ?? []) as $contribution) {
			if (is_array($contribution) === false || (string)($contribution['app'] ?? '') !== $appId) {
				continue;
			}

			foreach (($contribution['actions'] ?? []) as $action) {
				if (is_array($action) === false || (string)($action['id'] ?? '') !== $actionId) {
					continue;
				}

				if (is_array($action['draft'] ?? null) === true) {
					return $action;
				}
			}
		}

		return null;
	}//end draftingAction()

	/**
	 * The answers to keep: the action's own fields, as text, without its file
	 * fields. A file is uploaded after a record exists, so a draft can hold
	 * no file and the step that asks for one asks again.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 *
	 * @return array<string, mixed> The answers.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	private function answers(array $action): array {
		$answers = [];
		foreach ((array)($action['fields'] ?? []) as $field) {
			if (is_string($field) === false || $field === 'claims') {
				continue;
			}

			if ((($action['fieldConfigs'][$field]['type'] ?? null) === 'file')) {
				continue;
			}

			$value = $this->request->getParam($field);
			if (is_scalar($value) === true) {
				$answers[$field] = (string)$value;
			}
		}

		return $answers;
	}//end answers()

	/**
	 * When these answers go: the action's declared `retentionDays` (the
	 * normaliser has already held it to 1 to 90) counted from now.
	 *
	 * @param array<string, mixed> $action The normalised action.
	 *
	 * @return string The moment, ISO 8601.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-contribution-contract/spec.md#requirement-a-draft-of-a-create-or-endpoint-action-must-stay-with-portaliq-and-the-resident-req-smf-021
	 */
	private function expiry(array $action): string {
		$days = (int)($action['draft']['retentionDays'] ?? 30);

		return gmdate('c', ($this->time->getTime() + ($days * 86400)));
	}//end expiry()

	/**
	 * What the browser is told about a draft: the answers, the step and the
	 * date the screen shows. Never the stored id: the resident's session is
	 * how they reach their draft, not a string they could pass on.
	 *
	 * @param array<string, mixed> $draft The stored draft.
	 *
	 * @return array<string, mixed> The served draft.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/portal-intake-form/spec.md#requirement-save-and-resume-must-sit-in-the-step-navigation-req-smf-012
	 */
	private function served(array $draft): array {
		return [
			'answers'   => (array)($draft['answers'] ?? []),
			'step'      => (int)($draft['step'] ?? 0),
			'savedAt'   => (string)($draft['savedAt'] ?? ''),
			'expiresAt' => (string)($draft['expiresAt'] ?? ''),
		];
	}//end served()

	/**
	 * Now, as the store and the expiry both read it.
	 *
	 * @return string The moment, ISO 8601.
	 */
	private function now(): string {
		return gmdate('c', $this->time->getTime());
	}//end now()
}//end class
