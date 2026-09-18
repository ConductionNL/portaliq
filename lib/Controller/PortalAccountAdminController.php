<?php

/**
 * Portaliq Portal Account Admin Controller
 *
 * The two acts a clerk performs on the identity space: provision a portal
 * account for someone who has not logged in yet, and withdraw one that was
 * provisioned by mistake. Both are staff acts, gated by the ADR-023 action
 * `portal.provision`; neither is reachable from the portal itself.
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\ActionAuthService;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Provisioning and withdrawal of portal accounts by staff.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountAdminController extends Controller {
	/**
	 * The ADR-023 action both methods are gated by.
	 */
	public const ACTION_PROVISION = 'portal.provision';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalAccountService $accounts Provisions and withdraws accounts.
	 * @param ActionAuthService $actionAuth Decides whether this clerk may.
	 * @param IUserSession $userSession The staff user making the request.
	 * @param PortalInvitationService $invitations Invitations into the portal.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAccountService $accounts,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly PortalInvitationService $invitations,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Provision a portal account before its owner has ever logged in.
	 *
	 * @param string $audience The external audience the account belongs to.
	 * @param string $organisation The tenant slug.
	 * @param string $identityType One of the register's identityType enum, or ''.
	 * @param string $identityRef The identity reference, or ''.
	 * @param string $email A contact address, or ''.
	 * @param bool $verifiedEmail True when that address was verified out of band.
	 * @param string $displayName The name to greet the person by, or ''.
	 *
	 * @return JSONResponse The account, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per
	 * declared field of the account being provisioned.
	 */
	#[NoAdminRequired]
	public function provision(
		string $audience,
		string $organisation,
		string $identityType = '',
		string $identityRef = '',
		string $email = '',
		bool $verifiedEmail = false,
		string $displayName = '',
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$account = $this->accounts->provision(
			audience: $audience,
			organisation: $organisation,
			identityType: $identityType,
			identityRef: $identityRef,
			email: $email,
			verifiedEmail: $verifiedEmail,
			provisionedBy: $user->getUID(),
			displayName: $displayName
		);
		if ($account === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($account);
	}//end provision()

	/**
	 * Withdraw a pending account, with the reason on the row.
	 *
	 * @param string $subjectRef The account to withdraw.
	 * @param string $reason Why it is withdrawn.
	 *
	 * @return JSONResponse The outcome, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	#[NoAdminRequired]
	public function void(string $subjectRef, string $reason = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($reason === '') {
			return new JSONResponse(['error' => 'reason_required'], Http::STATUS_BAD_REQUEST);
		}

		$voided = $this->accounts->voidPending(subjectRef: $subjectRef, reason: $reason, voidedBy: $user->getUID());
		if ($voided === false) {
			return new JSONResponse(['error' => 'not_pending'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['status' => PortalAccountService::STATUS_VOID]);
	}//end void()

	/**
	 * Invite an address into the portal.
	 *
	 * @param string $email The address invited.
	 * @param string $organisation The tenant inviting.
	 * @param string $audience The audience the account will carry.
	 *
	 * @return JSONResponse The invitation's secret, for the mail, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[NoAdminRequired]
	public function invite(string $email, string $organisation, string $audience = 'client'): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$invited = $this->invitations->invite(
			email: $email,
			organisation: $organisation,
			audience: $audience,
			invitedBy: $user->getUID()
		);
		if ($invited === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($invited);
	}//end invite()

	/**
	 * The invitations this staff user sent, and what became of them.
	 *
	 * @param string $organisation The tenant.
	 *
	 * @return JSONResponse The sender's own invitations.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[NoAdminRequired]
	public function invitations(string $organisation = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		// The sender sees their own invitations, never another clerk's: the
		// list is scoped on the session's uid, not on a parameter.
		return new JSONResponse(['invitations' => $this->invitations->sentBy(invitedBy: $user->getUID(), organisation: $organisation)]);
	}//end invitations()
}//end class
