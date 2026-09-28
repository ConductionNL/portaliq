<?php

/**
 * Portaliq Access Request Admin Controller
 *
 * The owner's side of an access request: the organisation's staff list the
 * pending requests and grant or refuse each one. Every method is a staff act
 * behind the ADR-023 action `portal.answer-access-request`; being signed in
 * to Nextcloud is not enough.
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
 * @spec openspec/specs/portal-access-requests/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Lists, grants and refuses access requests, for staff holding the action.
 *
 * @spec openspec/specs/portal-access-requests/spec.md
 */
class AccessRequestAdminController extends Controller {
	/**
	 * The ADR-023 action every method is gated by.
	 */
	public const ACTION_ANSWER = 'portal.answer-access-request';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalAccessRequestService $requests The requests and their answers.
	 * @param ActionAuthService $actionAuth Decides whether this staff user may answer.
	 * @param IUserSession $userSession The staff user making the request.
	 * @param PortalResolver $portals The organisations the published portals serve.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAccessRequestService $requests,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly PortalResolver $portals,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The requests of one organisation, or of every organisation a published
	 * portal serves when none is named.
	 *
	 * @param string $organisation The tenant, or '' for every portal's tenant.
	 * @param string $state The state to list, `pending` by default.
	 *
	 * @return JSONResponse The requests, or 401 / 403.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
	 */
	#[NoAdminRequired]
	public function index(string $organisation = '', string $state = 'pending'): JSONResponse {
		$refusal = $this->requireAnswerRight();
		if ($refusal !== null) {
			return $refusal;
		}

		$organisations = [$organisation];
		if ($organisation === '') {
			$organisations = $this->portalOrganisations();
		}

		$requests = [];
		foreach ($organisations as $tenant) {
			$requests = array_merge($requests, $this->requests->forOwner(organisation: $tenant, state: $state));
		}

		return new JSONResponse(['requests' => $requests]);
	}//end index()

	/**
	 * Grant a request: it is answered `granted` and the asker gets a mandate.
	 *
	 * @param string $id The request.
	 * @param string $organisation The tenant the request belongs to.
	 *
	 * @return JSONResponse The new state, or 401 / 403 / 404 / 409 / 502.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-a-granted-request-opens-the-cases-req-iar-003
	 */
	#[NoAdminRequired]
	public function grant(string $id, string $organisation): JSONResponse {
		$refusal = $this->requireAnswerRight();
		if ($refusal !== null) {
			return $refusal;
		}

		$outcome = $this->requests->grant(id: $id, organisation: $organisation, decidedBy: $this->uid());

		return $this->answer(outcome: $outcome, state: 'granted');
	}//end grant()

	/**
	 * Refuse a request, with the reason the asker will read.
	 *
	 * @param string $id The request.
	 * @param string $organisation The tenant the request belongs to.
	 * @param string $reason Why it is refused.
	 *
	 * @return JSONResponse The new state, or 400 / 401 / 403 / 404 / 409.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
	 */
	#[NoAdminRequired]
	public function refuse(string $id, string $organisation, string $reason = ''): JSONResponse {
		$refusal = $this->requireAnswerRight();
		if ($refusal !== null) {
			return $refusal;
		}

		if (trim($reason) === '') {
			return new JSONResponse(['error' => 'reason_required'], Http::STATUS_BAD_REQUEST);
		}

		$outcome = $this->requests->refuse(id: $id, organisation: $organisation, reason: trim($reason), decidedBy: $this->uid());

		return $this->answer(outcome: $outcome, state: 'refused');
	}//end refuse()

	/**
	 * The refusal for a caller who may not answer, or null.
	 *
	 * @return JSONResponse|null
	 */
	private function requireAnswerRight(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_ANSWER);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end requireAnswerRight()

	/**
	 * The signed-in staff user's uid; only called after requireAnswerRight() passed.
	 *
	 * @return string
	 */
	private function uid(): string {
		$user = $this->userSession->getUser();
		if ($user instanceof IUser) {
			return $user->getUID();
		}

		return '';
	}//end uid()

	/**
	 * The HTTP answer for a service outcome.
	 *
	 * @param string $outcome One of the PortalAccessRequestService::OUTCOME_* values.
	 * @param string $state The state the request reads on success.
	 *
	 * @return JSONResponse
	 */
	private function answer(string $outcome, string $state): JSONResponse {
		return match ($outcome) {
			PortalAccessRequestService::OUTCOME_DONE => new JSONResponse(['state' => $state]),
			PortalAccessRequestService::OUTCOME_NOT_PENDING => new JSONResponse(['error' => 'not_pending'], Http::STATUS_CONFLICT),
			PortalAccessRequestService::OUTCOME_MANDATE_FAILED => new JSONResponse(['error' => 'mandate_not_recorded'], Http::STATUS_BAD_GATEWAY),
			default => new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND),
		};
	}//end answer()

	/**
	 * The organisations the published portals serve, each once.
	 *
	 * @return array<int, string>
	 */
	private function portalOrganisations(): array {
		$organisations = [];
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$tenant = trim((string)($portal['organisation'] ?? ''));
			if ($tenant !== '') {
				$organisations[$tenant] = $tenant;
			}
		}

		return array_values($organisations);
	}//end portalOrganisations()
}//end class
