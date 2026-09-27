<?php

/**
 * Portaliq Portal Account Self Controller
 *
 * What the bearer may do to their own account: change its details, confirm a
 * new address, ask for access they do not have, read the answers they were
 * given, and have the account removed.
 *
 * Every surface here is scoped to the bearer's own account. Nothing takes an
 * account identifier from the request: the subject always comes from the
 * bearer, so naming somebody else's account changes nothing.
 *
 * Split out of PortalIdentityController, which keeps the way in. The routes
 * and their URLs are unchanged; only the controller behind them moved.
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\PortalAccessRequestService;
use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The bearer's own account.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalAccountSelfController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param PortalSelfServiceService $selfService The account's own details.
	 * @param PortalAccessRequestService $accessRequests Asking for access.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly PortalSelfServiceService $selfService,
		private readonly PortalAccessRequestService $accessRequests,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Change the details of the bearer's own account.
	 *
	 * @param string $displayName A new name, or ''.
	 * @param string $email A new address, or ''.
	 * @param bool|null $emailNotifications The account's own opt-in/opt-out
	 *                                      for the email channel, or null
	 *                                      to leave it unchanged
	 *                                      (notification-preferences-per-role).
	 *
	 * @return JSONResponse Whether the change landed, and whether a
	 *                      confirmation is now waiting.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function updateDetails(string $displayName = '', string $email = '', ?bool $emailNotifications = null): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$updated = $this->selfService->updateDetails(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			displayName: $displayName,
			email: $email,
			emailNotifications: $emailNotifications
		);
		if ($updated === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		// The confirmation secret goes to the NEW address by mail; the answer
		// only says one is waiting, so the old session cannot read it.
		return new JSONResponse(['updated' => true, 'confirmationPending' => ($updated['confirmationToken'] !== '')]);
	}//end updateDetails()

	/**
	 * Confirm a new address through its link.
	 *
	 * @param string $token The secret from the confirmation mail.
	 *
	 * @return JSONResponse Whether the address is now in use.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function confirmEmail(string $token): JSONResponse {
		$confirmed = $this->selfService->confirmEmail(token: $token);
		if ($confirmed === null) {
			return new JSONResponse(['error' => 'link_not_valid'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['confirmed' => true]);
	}//end confirmEmail()

	/**
	 * Ask for the bearer's own account to be removed.
	 *
	 * @return JSONResponse Whether the account is gone.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 5, period: 60)]
	public function removeAccount(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$removed = $this->selfService->removeAccount(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if ($removed === false) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['removed' => true]);
	}//end removeAccount()

	/**
	 * Ask for access the bearer does not have.
	 *
	 * @param string $onBehalfOf The party whose cases are asked for.
	 * @param string $reason What the asker needs it for.
	 *
	 * @return JSONResponse The recorded request, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestAccess(string $onBehalfOf = '', string $reason = ''): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$recorded = $this->accessRequests->request(
			subjectRef: (string)($subject['subjectRef'] ?? ''),
			organisation: (string)($subject['organisation'] ?? ''),
			onBehalfOf: $onBehalfOf,
			reason: $reason
		);
		if ($recorded === null) {
			return new JSONResponse(['error' => 'reason_required'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['state' => 'pending']);
	}//end requestAccess()

	/**
	 * The requests the bearer has made, with the answers they were given.
	 *
	 * @return JSONResponse The asker's own requests.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function myAccessRequests(): JSONResponse {
		$subject = $this->subject();
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(['requests' => $this->accessRequests->madeBy(subjectRef: (string)($subject['subjectRef'] ?? ''))]);
	}//end myAccessRequests()

	/**
	 * The subject behind the bearer, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function subject(): ?array {
		return $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
	}//end subject()
}//end class
