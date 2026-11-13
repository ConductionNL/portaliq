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
 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\CaseTypeReaderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccountActivationServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalChallengeServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalContactServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalFormBindingResolverInterface;
use OCA\Portaliq\Kernel\Contracts\PortalIdentityMailerInterface;
use OCA\Portaliq\Kernel\Contracts\PortalInvitationServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalReferenceCaseServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalReferenceLinkServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalRegistrationPolicyServiceInterface;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\RequestedPortal;
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
 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
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
	 * @param PortalChallengeServiceInterface $challenge The portal's own challenge.
	 * @param PortalReferenceLinkServiceInterface $references The one-time reference link.
	 * @param PortalRegistrationPolicyServiceInterface $policy The registration policy.
	 * @param PortalInvitationServiceInterface $invitations Invitations into the portal.
	 * @param PortalAccountService $accounts Provisions a registration.
	 * @param CaseTypeReaderInterface $caseTypes Reads the case type's identity kinds.
	 * @param PortalFormBindingResolverInterface $bindings The case types this portal declares.
	 * @param PortalIdentityMailerInterface $mailer Mails the reference link to its address.
	 * @param PortalReferenceCaseServiceInterface $referenceCases The case behind a reference link.
	 * @param PortalSessionService $sessions Mints and resolves the reference session.
	 * @param PortalAccountActivationServiceInterface $activation Mints and spends the activation link (identity-ways-in-screens T03).
	 * @param PortalContactServiceInterface|null $contacts Joins a contact invitation the registration followed (own-contacts-and-invitations T05).
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalResolver $portals,
		private readonly PortalChallengeServiceInterface $challenge,
		private readonly PortalReferenceLinkServiceInterface $references,
		private readonly PortalRegistrationPolicyServiceInterface $policy,
		private readonly PortalInvitationServiceInterface $invitations,
		private readonly PortalAccountService $accounts,
		private readonly CaseTypeReaderInterface $caseTypes,
		private readonly PortalFormBindingResolverInterface $bindings,
		private readonly PortalIdentityMailerInterface $mailer,
		private readonly PortalReferenceCaseServiceInterface $referenceCases,
		private readonly PortalSessionService $sessions,
		private readonly PortalAccountActivationServiceInterface $activation,
		private readonly ?PortalContactServiceInterface $contacts = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * A challenge for a public surface, computed by the visitor's own browser.
	 *
	 * @param string $surface The surface asking, e.g. `form` or `registration`.
	 *
	 * @return JSONResponse The challenge, or 404 for an unknown portal.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function challenge(string $surface = 'form'): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$issued = $this->challenge->issue(site: $site, surface: $surface);
		// The form carries the honeypot empty, so it must know its name
		// (identity-ways-in-screens T02). A bot that fills every field fills it.
		$honeypot = (string)(((array)(((array)($site['authentication'] ?? []))['challenge'] ?? []))['honeypotField'] ?? '');
		if ($honeypot !== '') {
			$issued['honeypotField'] = $honeypot;
		}

		return new JSONResponse($issued);
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
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-every-way-in-sends-its-secret-by-mail-req-iwi-001
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function requestReferenceLink(string $register, string $schema, string $caseType, string $caseReference, string $email): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$run = new ReferenceLinkRun(
			site: $site,
			asked: ['register' => $register, 'schema' => $schema, 'caseType' => $caseType, 'caseReference' => $caseReference, 'email' => $email]
		);
		return $this->referenceLinkAnswer(run: $run);
	}//end requestReferenceLink()

	/**
	 * The answer to a reference-link request on a portal that exists: 404
	 * when the case type is not offered, the same "sent" for a case the
	 * address does not match as for a mailed link.
	 *
	 * @param ReferenceLinkRun $run The request.
	 *
	 * @return JSONResponse
	 */
	private function referenceLinkAnswer(ReferenceLinkRun $run): JSONResponse {
		$type = $this->offeredCaseType(run: $run);
		if ($type === null) {
			return new JSONResponse(['error' => 'route_not_offered'], Http::STATUS_NOT_FOUND);
		}

		$case = $this->linkableCase(run: $run);
		if ($case === null) {
			return new JSONResponse(['sent' => true, 'expiresAt' => $this->references->nominalExpiry()]);
		}

		$run->type     = $type;
		$run->linkable = $case;
		return $this->issueReferenceLink(run: $run);
	}//end referenceLinkAnswer()

	/**
	 * The case type the request names when this portal offers a reference
	 * link for it, else null.
	 *
	 * @param ReferenceLinkRun $run The request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function offeredCaseType(ReferenceLinkRun $run): ?array {
		// The register, schema and case type arrive from an anonymous request,
		// and the read behind them runs with RBAC and multitenancy off. So the
		// triple is checked against the portal's own published form bindings
		// BEFORE anything is read: outside that scope nothing is looked up.
		if ($this->bindings->caseTypeIsInPortalScope(
			portal: (string)($run->site['slug'] ?? ''),
			register: $run->register,
			schema: $run->schema,
			typeId: $run->caseType
		) === false) {
			return null;
		}

		// Outside the portal's scope, unknown, and `account` only are one
		// answer on purpose. A 403 here would tell an anonymous caller
		// which of the three it was, and that is an existence oracle for
		// case types on registers that are none of their business.
		$type = $this->caseTypes->readCaseType(register: $run->register, schema: $run->schema, id: $run->caseType);
		if ($type === null || $this->references->admitsReference(caseType: $type) === false) {
			return null;
		}

		return $type;
	}//end offeredCaseType()

	/**
	 * The case behind the number when the address is the one recorded on it.
	 *
	 * The address must be the one recorded on the case (portaliq#796).
	 * Otherwise anyone who knows or guesses a case number reads the case.
	 * A mismatch, an unknown case and a case app that declares no address
	 * field all answer exactly like a sent link, so this endpoint is no
	 * oracle for case numbers; no link is issued and no mail leaves.
	 *
	 * @param ReferenceLinkRun $run The request, with its case type offered.
	 *
	 * @return array{register: string, schema: string}|null
	 */
	private function linkableCase(ReferenceLinkRun $run): ?array {
		return $this->referenceCases->linkableCase(
			portal: (string)($run->site['slug'] ?? ''),
			register: $run->register,
			schema: $run->schema,
			caseType: $run->caseType,
			caseReference: $run->caseReference,
			email: $run->email,
			organisation: (string)($run->site['organisation'] ?? '')
		);
	}//end linkableCase()

	/**
	 * Issue the link and mail it.
	 *
	 * The secret goes out by mail, never in this answer: the address is
	 * what proves the asker is the person the case belongs to. A mail
	 * that did not leave answers the same, so the answer tells an
	 * anonymous caller nothing; the mailer logs the failure.
	 *
	 * @param ReferenceLinkRun $run The request, with its case found.
	 *
	 * @return JSONResponse
	 */
	private function issueReferenceLink(ReferenceLinkRun $run): JSONResponse {
		$issued = $this->references->issue(
			caseType: $run->type,
			caseReference: $run->caseReference,
			email: $run->email,
			organisation: (string)($run->site['organisation'] ?? ''),
			caseRegister: $run->linkable['register'],
			caseSchema: $run->linkable['schema']
		);
		if ($issued === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		$this->mailReferenceLink(run: $run, secret: $issued['token']);

		return new JSONResponse(['sent' => true, 'expiresAt' => $issued['expiresAt']]);
	}//end issueReferenceLink()

	/**
	 * Mail the issued link's secret to the address the request named.
	 *
	 * @param ReferenceLinkRun $run    The request.
	 * @param string           $secret The issued link's secret.
	 *
	 * @return void
	 */
	private function mailReferenceLink(ReferenceLinkRun $run, string $secret): void {
		$this->mailer->send(
			template: PortalIdentityMailerInterface::TEMPLATE_REFERENCE_LINK,
			email: $run->email,
			secret: $secret,
			organisation: (string)($run->site['organisation'] ?? ''),
			portal: $run->site
		);
	}//end mailReferenceLink()

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
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
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

		return $this->referenceSessionAnswer(redeemed: $redeemed);
	}//end redeemReferenceLink()

	/**
	 * The short, read-only session a redeemed link opens for its one case,
	 * with its bearer; 503 when none can be minted.
	 *
	 * @param array<string, string> $redeemed The redeemed link.
	 *
	 * @return JSONResponse
	 */
	private function referenceSessionAnswer(array $redeemed): JSONResponse {
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

		return $this->redeemedLinkAnswer(redeemed: $redeemed, session: $session);
	}//end referenceSessionAnswer()

	/**
	 * The case a redeemed link admits to, with its session's bearer.
	 *
	 * @param array<string, string> $redeemed The redeemed link.
	 * @param array<string, string> $session  The reference session minted for it.
	 *
	 * @return JSONResponse
	 */
	private function redeemedLinkAnswer(array $redeemed, array $session): JSONResponse {
		return new JSONResponse([
			'caseReference' => $redeemed['caseReference'],
			'organisation' => $redeemed['organisation'],
			'bearer' => $session['token'],
			'expiresAt' => $session['expiresAt'],
		]);
	}//end redeemedLinkAnswer()

	/**
	 * The one case a reference session may read, read only.
	 *
	 * Takes no identifier: the case is the row whose declared reference field
	 * equals the session's claim, so there is no other row to name.
	 *
	 * @return JSONResponse The case, 401 without a reference session, 404 when
	 *                      the case is not found.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/design.md
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
	 * @param string $contactInvitation The secret of a contact invitation the visitor followed, or ''.
	 *
	 * @return JSONResponse What became of the registration.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
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
		string $contactInvitation = '',
	): JSONResponse {
		$site = $this->site();
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$run = new RegistrationRun(site: $site, email: $email);
		$run->displayName       = $displayName;
		$run->contactInvitation = $contactInvitation;
		$run->challenge         = ['nonce' => $nonce, 'solution' => $solution, 'expiresAt' => $expiresAt, 'signature' => $signature];
		return $this->registerOnPortal(run: $run);
	}//end register()

	/**
	 * Register on a portal that exists: refused when the portal offers no
	 * registration, the challenge is not solved or the policy declines the
	 * address; else the account is provisioned.
	 *
	 * @param RegistrationRun $run The registration.
	 *
	 * @return JSONResponse
	 */
	private function registerOnPortal(RegistrationRun $run): JSONResponse {
		if ($this->policy->isOffered(site: $run->site) === false) {
			return new JSONResponse(['error' => 'registration_off'], Http::STATUS_FORBIDDEN);
		}

		if ($this->acceptsRegistrationChallenge(run: $run) === false) {
			return new JSONResponse(['error' => 'challenge_failed'], Http::STATUS_FORBIDDEN);
		}

		$decision = $this->policy->decide(site: $run->site, email: $run->email);
		if ($decision['accepted'] === false) {
			return new JSONResponse(['error' => $decision['reason']], Http::STATUS_FORBIDDEN);
		}

		return $this->provisionRegistration(run: $run, decision: $decision);
	}//end registerOnPortal()

	/**
	 * Whether the registration carries a solved challenge.
	 *
	 * The credential this endpoint authenticates on: a nonce this instance
	 * signed, for this surface, still inside its window, with the proof of
	 * work done over it. The signature is what makes the nonce ours; without
	 * it the caller could mint their own and reuse it for ever.
	 *
	 * @param RegistrationRun $run The registration.
	 *
	 * @return bool
	 */
	private function acceptsRegistrationChallenge(RegistrationRun $run): bool {
		return $this->challenge->accepts(
			site: $run->site,
			surface: 'registration',
			submission: (array)$this->request->getParams(),
			nonce: $run->challenge['nonce'],
			solution: $run->challenge['solution'],
			expiresAt: $run->challenge['expiresAt'],
			signature: $run->challenge['signature']
		);
	}//end acceptsRegistrationChallenge()

	/**
	 * Provision the account the policy accepted, hold a followed contact
	 * invitation for it and, under activation, mail its link.
	 *
	 * @param RegistrationRun      $run      The registration.
	 * @param array<string, mixed> $decision The policy's decision.
	 *
	 * @return JSONResponse
	 */
	private function provisionRegistration(RegistrationRun $run, array $decision): JSONResponse {
		$account = $this->selfRegisteredAccount(run: $run);
		if ($account === null) {
			return new JSONResponse(['error' => 'refused'], Http::STATUS_BAD_REQUEST);
		}

		$this->holdContactInvitation(run: $run, account: $account);

		if ($decision['reason'] === PortalRegistrationPolicyServiceInterface::POLICY_ACTIVATION) {
			$this->mailActivation(subjectRef: $account['subjectRef'], email: $run->email, site: $run->site);
		}

		// The subjectRef is deliberately not answered: the account cannot sign
		// in yet, and handing out its reference would only make it guessable.
		return new JSONResponse(['status' => $account['status'], 'awaiting' => $decision['reason']]);
	}//end provisionRegistration()

	/**
	 * The self-registered account for the address, or null.
	 *
	 * @param RegistrationRun $run The registration.
	 *
	 * @return array<string, mixed>|null
	 */
	private function selfRegisteredAccount(RegistrationRun $run): ?array {
		return $this->accounts->provision(
			audience: 'client',
			organisation: (string)($run->site['organisation'] ?? ''),
			email: $run->email,
			// Neither policy trusts the address yet: approval waits for a
			// person, activation waits for the mail.
			verifiedEmail: false,
			provisionedBy: PortalAccountService::SELF_REGISTRATION,
			displayName: $run->displayName
		);
	}//end selfRegisteredAccount()

	/**
	 * An invitation from a resident is held for the new account, so its
	 * activation joins both sides, in whichever tab the link opens.
	 *
	 * @param RegistrationRun      $run     The registration.
	 * @param array<string, mixed> $account The new account.
	 *
	 * @return void
	 */
	private function holdContactInvitation(RegistrationRun $run, array $account): void {
		if ($run->contactInvitation === '') {
			return;
		}

		$this->contacts?->holdForRegistration(
			token: $run->contactInvitation,
			newRef: (string)$account['subjectRef'],
			organisation: (string)($run->site['organisation'] ?? '')
		);
	}//end holdContactInvitation()

	/**
	 * Follow the activation link of a self-registration: the account becomes
	 * active with a verified address (identity-ways-in-screens D4).
	 *
	 * @param string $token The secret from the activation mail.
	 *
	 * @return JSONResponse Whether the account is ready.
	 *
	 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 20, period: 60)]
	public function activate(string $token): JSONResponse {
		$account = $this->activation->activate(token: $token);
		if ($account === null) {
			// Used, expired and unknown are one answer.
			return new JSONResponse(['error' => 'activation_not_valid'], Http::STATUS_FORBIDDEN);
		}

		// A contact invitation the registration followed joins both sides now,
		// without a second approval (REQ-ROC-003).
		$this->contacts?->joinOnActivation(account: $account);

		return new JSONResponse(['activated' => true]);
	}//end activate()

	/**
	 * The address a contact invitation went to, so "Create an account" shows
	 * it filled in. Only an open invitation of this portal's tenant answers;
	 * unknown, expired and used are one answer.
	 *
	 * @param string $token The secret from the invitation mail.
	 *
	 * @return JSONResponse `{email}`, or 404.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function contactInvitation(string $token = ''): JSONResponse {
		$site  = $this->site();
		$email = '';
		if ($site !== null && $this->contacts !== null) {
			$email = $this->contacts->invitationEmail(token: $token, organisation: (string)($site['organisation'] ?? ''));
		}

		if ($email === '') {
			return new JSONResponse(['error' => 'invitation_not_valid'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['email' => $email]);
	}//end contactInvitation()

	/**
	 * Mail the activation link of a new self-registration. The secret goes
	 * out by mail only; a pending account that is not a self-registration
	 * gets no link, and a mail that did not leave answers the same.
	 *
	 * @param string $subjectRef The account.
	 * @param string $email The address it registered with.
	 * @param array<string, mixed> $site The portal being visited.
	 *
	 * @return void
	 */
	private function mailActivation(string $subjectRef, string $email, array $site): void {
		$token = $this->activation->issue(subjectRef: $subjectRef);
		if ($token === null) {
			return;
		}

		$this->mailer->send(
			template: PortalIdentityMailerInterface::TEMPLATE_REGISTRATION_ACTIVATION,
			email: $email,
			secret: $token,
			organisation: (string)($site['organisation'] ?? ''),
			portal: $site
		);
	}//end mailActivation()

	/**
	 * Accept an invitation, which provisions the account for its address.
	 *
	 * @param string $token The secret from the invitation mail.
	 *
	 * @return JSONResponse Whether the invitation admitted anybody.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
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
		// The SPA names the portal it is served as, the way `/site` and the
		// runtime config do; on a shared host the host alone names none.
		// A named portal that does not exist is a miss, never the host's.
		return $this->portals->resolve(request: $this->request, portalSlug: (new RequestedPortal())->slug(request: $this->request));
	}//end site()
}//end class
