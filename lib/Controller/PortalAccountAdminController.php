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
use OCA\Portaliq\Service\Identity\EmailLink\SignInAddressChange;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
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
	 * @param PortalIdentityMailer $mailer Mails the invitation to its address.
	 * @param SignInAddressChange|null $signInAddresses Changes an e-mail account's sign-in address (sign-in-with-an-email-link H2).
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAccountService $accounts,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly PortalInvitationService $invitations,
		private readonly PortalIdentityMailer $mailer,
		private readonly ?SignInAddressChange $signInAddresses = null,
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
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `verifiedEmail` is a
	 * field of the row, and this method only carries it to the service.
	 * Nothing here branches on it, so there are not two acts to separate.
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
	 * @return JSONResponse That it was sent and until when, or a refusal.
	 *                      Never the secret: that is in the mail only.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
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

		// A secret shown to a clerk is a secret a clerk could use, and the
		// mail is what proves the address. So it goes to the invited address
		// and the answer carries only the state and the expiry.
		$sent = $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_INVITATION,
			email: $email,
			secret: (string)$invited['token'],
			organisation: $organisation
		);
		if ($sent === false) {
			// Nobody holds the secret now, so this invitation admits nobody
			// and runs out on its own. The clerk sends a new one.
			return new JSONResponse(['error' => 'mail_not_sent'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(['state' => 'sent', 'expiresAt' => $invited['expiresAt']]);
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

	/**
	 * Withdraw an invitation that was not accepted.
	 *
	 * @param string $id The invitation's id.
	 * @param string $organisation The tenant the clerk works for.
	 *
	 * @return JSONResponse `{state: revoked}`, or a refusal.
	 *
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-see-and-withdraw-invitations-req-isa-002
	 */
	#[NoAdminRequired]
	public function revokeInvitation(string $id, string $organisation = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$outcome = $this->invitations->revoke(id: $id, organisation: $organisation);
		if ($outcome !== '') {
			return new JSONResponse(['error' => $outcome], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['state' => 'revoked']);
	}//end revokeInvitation()

	/**
	 * Approve a self-registration that waits for a decision.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return JSONResponse `{status: active}`, or `not_pending`.
	 *
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
	 */
	#[NoAdminRequired]
	public function approve(string $subjectRef): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		if ($this->accounts->approvePending(subjectRef: $subjectRef) === false) {
			return new JSONResponse(['error' => 'not_pending'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['status' => PortalAccountService::STATUS_ACTIVE]);
	}//end approve()

	/**
	 * Refuse a self-registration, with the reason on the row.
	 *
	 * @param string $subjectRef The account.
	 * @param string $reason Why it is refused.
	 *
	 * @return JSONResponse `{status: void}`, or a refusal.
	 *
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
	 */
	#[NoAdminRequired]
	public function refuse(string $subjectRef, string $reason = ''): JSONResponse {
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

		if ($this->accounts->voidPending(subjectRef: $subjectRef, reason: $reason, voidedBy: $user->getUID()) === false) {
			return new JSONResponse(['error' => 'not_pending'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['status' => PortalAccountService::STATUS_VOID]);
	}//end refuse()

	/**
	 * Staff change the sign-in address of an `email` account (security review
	 * H2). The unspent links are voided and the old address is told.
	 *
	 * @param string $subjectRef The account.
	 * @param string $address    The new sign-in address.
	 *
	 * @return JSONResponse `{signInAddress}` or a refusal.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-an-e-mail-link-session-is-a-fresh-low-session-that-cannot-raise-itself-req-iwi-011
	 */
	#[NoAdminRequired]
	public function signInAddress(string $subjectRef, string $address = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'not_authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::ACTION_PROVISION);
		} catch (OCSForbiddenException $exception) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null || $this->signInAddresses === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$outcome = $this->signInAddresses->change(account: $account, newAddress: $address, byStaff: true);
		if ($outcome === SignInAddressChange::FAILED) {
			return new JSONResponse(['error' => 'save_failed'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($outcome !== SignInAddressChange::CHANGED) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['signInAddress' => strtolower(trim($address))]);
	}//end signInAddress()
}//end class
