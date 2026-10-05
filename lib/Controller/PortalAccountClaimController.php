<?php

/**
 * Portaliq Portal Account Claim Controller
 *
 * The signed-in person hands back the one-time secret of an invitation, and
 * the waiting account behind it joins the account they signed in with.
 *
 * The account that receives is always the bearer's own. The session must be
 * at trust level substantial or higher: a waiting account can carry an
 * app's claim on a child or a case, and that is not handed to a session
 * that proved less. Wrong, expired and already used are one answer.
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
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Auth\PortalProtected;
use OCA\Portaliq\Service\Identity\WaitingAccountInvitation;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Redeems an invitation's secret for the bearer.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class PortalAccountClaimController extends Controller implements PortalProtected {
	/**
	 * The trust a session needs before a waiting account may join it.
	 */
	public const MIN_TRUST = 'substantial';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalSessionService $session Resolves the subject from the bearer.
	 * @param WaitingAccountInvitation $invitations Redeems the secret.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly WaitingAccountInvitation $invitations,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Redeem the secret of an invitation for the bearer's own account.
	 *
	 * @param string $secret The secret from the invitation.
	 *
	 * @return JSONResponse 200 when the waiting account joined, 401 without
	 *                      a session, 403 `trust_too_low` below substantial,
	 *                      429 after too many wrong secrets, and one 403
	 *                      `invitation_not_valid` for wrong, expired and used.
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function redeem(string $secret = ''): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		// About the session, not the secret: it is checked before the secret
		// is looked at, so it tells the caller nothing about any invitation.
		if ($this->session::trustSatisfies(subjectTrust: ($subject['trust'] ?? ''), minTrust: self::MIN_TRUST) === false) {
			return new JSONResponse(['error' => 'trust_too_low'], Http::STATUS_FORBIDDEN);
		}

		$result = $this->invitations->redeem(subject: $subject, secret: $secret);
		if ($result === WaitingAccountInvitation::LOCKED) {
			return new JSONResponse(['error' => 'too_many_attempts'], Http::STATUS_TOO_MANY_REQUESTS);
		}

		if ($result !== WaitingAccountInvitation::CLAIMED) {
			return new JSONResponse(['error' => 'invitation_not_valid'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['claimed' => true]);
	}//end redeem()
}//end class
