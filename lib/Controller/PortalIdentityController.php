<?php

/**
 * Portaliq Portal Identity Controller
 *
 * The way in to the identity space: the challenge in front of a public
 * surface, the one-time reference link for a case type that admits it,
 * self-registration under the portal's policy, and accepting an invitation.
 *
 * Everything here is anonymous by design. What the bearer may then do to
 * their OWN account lives next door in PortalAccountSelfController, because
 * getting in and running an account you already have are two jobs.
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
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The citizen's own identity surfaces.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one dependency per
 * distinct act; a facade would hide which boundary each one crosses.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)  -- see above.
 */
class PortalIdentityController extends Controller implements PortalProtected {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalResolver $portals Resolves the portal being visited.
	 * @param PortalChallengeService $challenge The portal's own challenge.
	 * @param PortalReferenceLinkService $references The one-time reference link.
	 * @param PortalRegistrationPolicyService $policy The registration policy.
	 * @param PortalInvitationService $invitations Invitations into the portal.
	 * @param PortalAccountService $accounts Provisions a registration.
	 * @param CaseTypeReader $caseTypes Reads the case type's identity kinds.
	 * @param PortalFormBindingResolver $bindings The case types this portal declares.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalChallengeService $challenge,
		private readonly PortalReferenceLinkService $references,
		private readonly PortalRegistrationPolicyService $policy,
		private readonly PortalInvitationService $invitations,
		private readonly PortalAccountService $accounts,
		private readonly CaseTypeReader $caseTypes,
		private readonly PortalFormBindingResolver $bindings,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A challenge for a public surface, computed by the visitor's own browser.
	 *
	 * @param string $surface The surface asking, e.g. `form` or `registration`.
	 *
	 * @return JSONResponse The challenge, or 404 for an unknown portal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function challenge(string $surface = 'form'): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->challenge->issue(site: $site, surface: $surface));
	}//end challenge()

	/**
	 * Ask for a one-time link to a case, on its number and an address.
	 *
	 * @param string $register The register the case type lives in.
	 * @param string $schema The schema the case type lives in.
	 * @param string $caseType The case type.
	 * @param string $caseReference The case number.
	 * @param string $email The address the link goes to.
	 *
	 * @return JSONResponse Whether a link was issued, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestReferenceLink(string $register, string $schema, string $caseType, string $caseReference, string $email): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		// The register, schema and case type arrive from an anonymous request,
		// and the read behind them runs with RBAC and multitenancy off. So the
		// triple is checked against the portal's own published form bindings
		// BEFORE anything is read: outside that scope nothing is looked up.
		if ($this->bindings->caseTypeIsInPortalScope(
			portal: (string)($site['slug'] ?? ''),
			register: $register,
			schema: $schema,
			typeId: $caseType
		) === false
		) {
			return new JSONResponse(['error' => 'route_not_offered'], Http::STATUS_NOT_FOUND);
		}

		$type = $this->caseTypes->readCaseType(register: $register, schema: $schema, id: $caseType);
		if ($type === null || $this->references->admitsReference(caseType: $type) === false) {
			// Outside the portal's scope, unknown, and `account` only are one
			// answer on purpose. A 403 here would tell an anonymous caller
			// which of the three it was, and that is an existence oracle for
			// case types on registers that are none of their business.
			return new JSONResponse(['error' => 'route_not_offered'], Http::STATUS_NOT_FOUND);
		}

		$issued = $this->references->issue(
			caseType: $type,
			caseReference: $caseReference,
			email: $email,
			organisation: (string)($site['organisation'] ?? '')
		);
		if ($issued === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		// The secret goes out by mail, never in this answer: the address is
		// what proves the asker is the person the case belongs to.
		return new JSONResponse(['sent' => true, 'expiresAt' => $issued['expiresAt']]);
	}//end requestReferenceLink()

	/**
	 * Follow a reference link, once.
	 *
	 * @param string $token The secret from the mail.
	 *
	 * @return JSONResponse The case the link admits to, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function redeemReferenceLink(string $token): JSONResponse {
		$redeemed = $this->references->redeem(token: $token);
		if ($redeemed === null) {
			// Used, expired and unknown are one answer: which of the three it
			// was is not the visitor's business.
			return new JSONResponse(['error' => 'link_not_valid'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse($redeemed);
	}//end redeemReferenceLink()

	/**
	 * Register on this portal, under its policy.
	 *
	 * @param string $email The address registering.
	 * @param string $displayName The name to greet them by.
	 * @param string $nonce The challenge nonce they were issued.
	 * @param string $solution Their solution to it.
	 * @param int $expiresAt The expiry issued with the nonce.
	 * @param string $signature This instance's signature over the nonce.
	 *
	 * @return JSONResponse What became of the registration.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function register(
		string $email,
		string $displayName = '',
		string $nonce = '',
		string $solution = '',
		int $expiresAt = 0,
		string $signature = '',
	): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->policy->isOffered(site: $site) === false) {
			return new JSONResponse(['error' => 'registration_off'], Http::STATUS_FORBIDDEN);
		}

		// The credential this endpoint authenticates on: a nonce this instance
		// signed, for this surface, still inside its window, with the proof of
		// work done over it. $signature is what makes the nonce ours; without
		// it the caller could mint their own and reuse it for ever.
		$accepted = $this->challenge->accepts(
			site: $site,
			surface: 'registration',
			submission: (array)$this->request->getParams(),
			nonce: $nonce,
			solution: $solution,
			expiresAt: $expiresAt,
			signature: $signature
		);
		if ($accepted === false) {
			return new JSONResponse(['error' => 'challenge_failed'], Http::STATUS_FORBIDDEN);
		}

		$decision = $this->policy->decide(site: $site, email: $email);
		if ($decision['accepted'] === false) {
			return new JSONResponse(['error' => $decision['reason']], Http::STATUS_FORBIDDEN);
		}

		$account = $this->accounts->provision(
			audience: 'client',
			organisation: (string)($site['organisation'] ?? ''),
			email: $email,
			// Neither policy trusts the address yet: approval waits for a
			// person, activation waits for the mail.
			verifiedEmail: false,
			provisionedBy: 'self-registration',
			displayName: $displayName
		);
		if ($account === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		// The subjectRef is deliberately not answered: the account cannot sign
		// in yet, and handing out its reference would only make it guessable.
		return new JSONResponse(['status' => $account['status'], 'awaiting' => $decision['reason']]);
	}//end register()

	/**
	 * Accept an invitation, which provisions the account for its address.
	 *
	 * @param string $token The secret from the invitation mail.
	 *
	 * @return JSONResponse Whether the invitation admitted anybody.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function acceptInvitation(string $token): JSONResponse {
		$accepted = $this->invitations->accept(token: $token);
		if ($accepted === null) {
			return new JSONResponse(['error' => 'invitation_not_valid'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['accepted' => true]);
	}//end acceptInvitation()



	/**
	 * The portal being visited, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function site(): ?array {
		return $this->portals->resolve(request: $this->request);
	}//end site()
}//end class
