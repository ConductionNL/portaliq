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
 * getting in and running an account you already have are two jobs. So this
 * controller does NOT carry the PortalProtected marker: behind the bearer
 * gate every way in answered 401 to the visitor it exists for (portaliq#795).
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
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalChallengeService;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Service\Identity\PortalReferenceCaseService;
use OCA\Portaliq\Service\Identity\PortalInvitationService;
use OCA\Portaliq\Service\Identity\PortalReferenceLinkService;
use OCA\Portaliq\Service\Identity\PortalRegistrationPolicyService;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
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
class PortalIdentityController extends Controller {

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
	 * @param PortalIdentityMailer $mailer Mails the reference link to its address.
	 * @param PortalReferenceCaseService $referenceCases The case behind a reference link.
	 * @param PortalSessionService $sessions Mints and resolves the reference session.
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
		private readonly PortalIdentityMailer $mailer,
		private readonly PortalReferenceCaseService $referenceCases,
		private readonly PortalSessionService $sessions,
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
	 * @spec openspec/changes/identity-ways-in-screens/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
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

		// The address must be the one recorded on the case (portaliq#796).
		// Otherwise anyone who knows or guesses a case number reads the case.
		// A mismatch, an unknown case and a case app that declares no address
		// field all answer exactly like a sent link, so this endpoint is no
		// oracle for case numbers; no link is issued and no mail leaves.
		$case = $this->referenceCases->linkableCase(
			portal: (string)($site['slug'] ?? ''),
			register: $register,
			schema: $schema,
			caseType: $caseType,
			caseReference: $caseReference,
			email: $email,
			organisation: (string)($site['organisation'] ?? '')
		);
		if ($case === null) {
			return new JSONResponse(['sent' => true, 'expiresAt' => $this->references->nominalExpiry()]);
		}

		$issued = $this->references->issue(
			caseType: $type,
			caseReference: $caseReference,
			email: $email,
			organisation: (string)($site['organisation'] ?? ''),
			caseRegister: $case['register'],
			caseSchema: $case['schema']
		);
		if ($issued === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		// The secret goes out by mail, never in this answer: the address is
		// what proves the asker is the person the case belongs to. A mail
		// that did not leave answers the same, so the answer tells an
		// anonymous caller nothing; the mailer logs the failure.
		$this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_REFERENCE_LINK,
			email: $email,
			secret: $issued['token'],
			organisation: (string)($site['organisation'] ?? ''),
			portal: $site
		);

		return new JSONResponse(['sent' => true, 'expiresAt' => $issued['expiresAt']]);
	}//end requestReferenceLink()

	/**
	 * Follow a reference link, once. The link opens a short, read-only
	 * session for its one case (identity-ways-in-screens D2): the answer
	 * carries that session's bearer, which reads the case through
	 * `referenceCase()` and nothing else.
	 *
	 * @param string $token The secret from the mail.
	 *
	 * @return JSONResponse The case the link admits to with its bearer, or a refusal.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/identity-ways-in-screens/design.md
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

		$session = $this->sessions->issueReferenceSession(
			linkId: $redeemed['linkId'],
			caseReference: $redeemed['caseReference'],
			organisation: $redeemed['organisation'],
			register: $redeemed['register'],
			schema: $redeemed['schema']
		);
		if ($session === null) {
			// The link is spent either way: a link that worked twice would be
			// worse than asking for a new one.
			return new JSONResponse(['error' => 'session_unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse([
			'caseReference' => $redeemed['caseReference'],
			'organisation' => $redeemed['organisation'],
			'bearer' => $session['token'],
			'expiresAt' => $session['expiresAt'],
		]);
	}//end redeemReferenceLink()

	/**
	 * The one case a reference session may read, read only.
	 *
	 * Takes no identifier: the case is the row whose declared reference field
	 * equals the session's claim, so there is no other row to name.
	 *
	 * @return JSONResponse The case, 401 without a reference session, 404 when
	 *                      the case is not found.
	 *
	 * @spec openspec/changes/identity-ways-in-screens/design.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function referenceCase(): JSONResponse {
		$reference = $this->sessions->resolveReferenceFromBearer($this->request->getHeader('Authorization'));
		if ($reference === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$case = $this->referenceCases->read(reference: $reference);
		if ($case === null) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['case' => $case, 'caseReference' => $reference['caseReference'], 'readOnly' => true]);
	}//end referenceCase()

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
			provisionedBy: PortalAccountService::SELF_REGISTRATION,
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
