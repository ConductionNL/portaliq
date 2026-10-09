<?php

/**
 * Portaliq Session Admin Controller
 *
 * Admin-only incident-response action for the portal auth edge
 * (portal-auth-edge-session-hardening): revoke every active `portalSession`
 * for a given Organisation, e.g. when a supplier reports a stolen device.
 *
 * Deliberately carries NO auth attribute: Nextcloud's SecurityMiddleware
 * default (no #[NoAdminRequired], no #[PublicPage]) is "instance admin + CSRF
 * token required" — exactly the posture this incident-response action needs.
 * #[AuthorizedAdminSetting] (the delegated-admin alternative) is NOT used
 * here because it requires the referenced settings class to implement
 * IDelegatedSettings; `AdminSettings` deliberately stays a plain `ISettings`
 * (full-admin-only, per its own docblock) — see that class if delegated
 * (group-restricted) admin access to this action becomes a requirement.
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
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin-only session revocation for the portal auth edge.
 *
 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
 */
class SessionAdminController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PortalSessionService $session The session service.
	 * @param IUserSession $userSession The signed-in admin (named in the audit trail).
	 * @param EmailLinkTokens|null $emailLinks Voids one account's unspent e-mail links (sign-in-with-an-email-link).
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly IUserSession $userSession,
		private readonly ?EmailLinkTokens $emailLinks = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Revoke every active portal session for an Organisation.
	 *
	 * @param string $organisation The tenant (OpenRegister Organisation) to
	 *                             revoke every session for.
	 *
	 * @return JSONResponse `{revoked, failed, complete}`; 503 with
	 *                      `error: revoke_incomplete` when not every live
	 *                      session could be read or revoked (security review
	 *                      S5); 400 when `organisation` is empty.
	 *
	 * @auth admin-only Incident-response action that revokes every active
	 *       portalSession for a whole tenant. Nextcloud expresses "instance
	 *       admin + CSRF" as the ABSENCE of an opt-out attribute, so there is
	 *       no attribute to add; this tag is the declaration. Deliberately not
	 *       the AuthorizedAdminSetting attribute, which would widen it to
	 *       delegated admins — see the class docblock.
	 *
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.2
	 */
	public function revokeOrganisation(string $organisation = ''): JSONResponse {
		if ($organisation === '') {
			return new JSONResponse(['error' => 'organisation_required'], Http::STATUS_BAD_REQUEST);
		}

		// The acting admin is named in the audit trail (security review S6).
		$admin  = (string)$this->userSession->getUser()?->getUID();
		$result = $this->session->revokeAllForOrganisation($organisation, $admin);
		if ($result['complete'] === false) {
			return new JSONResponse(['error' => 'revoke_incomplete'] + $result, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result);
	}//end revokeOrganisation()

	/**
	 * Revoke one account's unspent e-mail links and every live session of it,
	 * without touching the rest of its organisation.
	 *
	 * @param string $subjectRef   The account.
	 * @param string $organisation The account's organisation.
	 *
	 * @return JSONResponse `{links, revoked, failed, complete}`; 503 when not
	 *                      every session could be revoked; 400 when a field is empty.
	 *
	 * @auth admin-only Incident response on one portal account, the same
	 *       posture as revokeOrganisation(): instance admin + CSRF, expressed
	 *       by the absence of an opt-out attribute.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-staff-can-revoke-an-accounts-e-mail-links-and-sessions-req-iwi-013
	 */
	public function revokeAccount(string $subjectRef = '', string $organisation = ''): JSONResponse {
		if ($subjectRef === '' || $organisation === '') {
			return new JSONResponse(['error' => 'account_required'], Http::STATUS_BAD_REQUEST);
		}

		$admin  = (string)$this->userSession->getUser()?->getUID();
		$links  = (int)$this->emailLinks?->voidFor(subjectRef: $subjectRef, organisation: $organisation);
		$result = ['links' => $links] + $this->session->revokeAllForSubject($subjectRef, $organisation, $admin);
		if ($result['complete'] === false) {
			return new JSONResponse(['error' => 'revoke_incomplete'] + $result, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($result);
	}//end revokeAccount()
}//end class
