<?php

/**
 * Portaliq Session Controller
 *
 * The public auth-edge HTTP surface for the portal SPA. `index()` resolves the
 * caller's bearer to a server-derived subject (fail-closed). `oidcStart()` and
 * `oidcCallback()` are the live, routed broker login: a generic OIDC Relying
 * Party that sends the caller to a DigiD / eHerkenning / eIDAS broker and mints
 * a portal session from the validated ID token (portal-oidc-broker-login; the
 * integriq broker route lives in BrokerSessionController). `nextcloud()` signs
 * in with an existing Nextcloud account for portals that declare that mode.
 * `devLogin()` mints a session WITHOUT a real IdP — it is gated behind
 * Nextcloud debug mode or an explicit app flag so it can never issue tokens in
 * production; it exists so the portal is exercisable without a configured
 * broker. `refresh()` rotates the bearer and `logout()` ends the client session.
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
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T06
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\OidcClientService;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\Identity\ContactAddressValues;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Signin\OrganisationLoginConfig;
use OCA\Portaliq\Service\Signin\SiteReturnAddress;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Public auth-edge endpoints for the portal SPA.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T06
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T07
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- one dependency per
 * distinct OIDC responsibility (config resolution, protocol mechanics, claim
 * mapping, state storage, account resolution) — see PortalSessionService's
 * identical rationale; collapsing them would hide the fail-closed seams this
 * edge depends on.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) -- every sign-in route
 * the portal offers (dev, OIDC start and callback, Nextcloud, refresh,
 * logout) is one public entry with its own fail-closed guards, and the site's
 * `?portal=` start (#802) added the last branch. Splitting the routes over
 * controllers would scatter one auth edge without removing a single guard.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the constructor mirrors
 * that coupling 1:1; folding services into a facade would only relocate the
 * same count behind one more layer.
 */
class SessionController extends Controller {
	/**
	 * The identical, generic OIDC failure response (design.md, ADR-005): every
	 * validation/config/lookup failure in `oidcStart()`/`oidcCallback()`
	 * returns EXACTLY this — no response ever reveals WHICH check failed.
	 */
	private const OIDC_GENERIC_ERROR = 'oidc_failed';

	/**
	 * The broker errors that mean "the resident must interact" (OpenID Connect
	 * Core 3.1.2.6). A silent attempt answered with one of these is not a
	 * failure: the resident lands on the login screen with no message
	 * (signin-session-idle-warning-and-sso D5).
	 */
	private const SILENT_LOGIN_ERRORS = ['login_required', 'interaction_required', 'consent_required', 'account_selection_required'];

	/**
	 * Where an OIDC callback lands when no `returnTo` was stored: the site's
	 * OWN route, resolved through the URL generator so it carries the app's
	 * web-root (`/apps/portaliq/site`). A bare literal resolved to the
	 * Nextcloud ROOT, which 404s, so every OIDC login landed on "Page not
	 * found". It named the React portal (`portalPage.index`) until the site
	 * replaced it (site-reaches-portal-parity REQ-SRP-049).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-server-must-link-to-the-site-directly-req-srp-049
	 */
	private function portalReturnTo(): string {
		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.portalPage.site');
	}//end portalReturnTo()

	/**
	 * Where a login returns: the serving portal's own address when the login
	 * was started from a portal that exists, so its title and branding
	 * survive the sign-in; else the plain portal address. Only a resolved
	 * portal's slug is echoed, never raw input (portal-signin-on-its-own-address).
	 *
	 * @param array<string, mixed>|null $site The resolved serving portal, or null.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-signin-on-its-own-address/tasks.md#T3
	 */
	private function returnToPortal(?array $site): string {
		$slug = (string)($site['slug'] ?? '');
		if ($slug === '') {
			return $this->portalReturnTo();
		}

		return $this->portalReturnTo() . '?portal=' . rawurlencode($slug);
	}//end returnToPortal()

	/**
	 * The site page a login returns to, or '' when `$returnTo` is not a page
	 * on the site route.
	 *
	 * @param string $returnTo The address the site sent.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	private function siteReturn(string $returnTo): string {
		return (new SiteReturnAddress())->accept(
			candidate: $returnTo,
			sitePath: $this->urlGenerator->linkToRoute(Application::APP_ID . '.portalPage.site')
		);
	}//end siteReturn()

	/**
	 * Where a login returns: the site page it started on, else the portal.
	 *
	 * @param string                    $siteReturn The accepted site page, or ''.
	 * @param array<string, mixed>|null $site       The serving portal, or null.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	private function returnAddress(string $siteReturn, ?array $site): string {
		if ($siteReturn !== '') {
			return $siteReturn;
		}

		return $this->returnToPortal(site: $site);
	}//end returnAddress()

	/**
	 * The redirect to integriq's broker start, carrying the resolved portal
	 * and the site page to return to when there are any.
	 *
	 * @param string                    $org        The organisation slug.
	 * @param string                    $provider   The provider.
	 * @param string                    $siteReturn The accepted site page, or ''.
	 * @param array<string, mixed>|null $site       The resolved serving portal, or null.
	 *
	 * @return RedirectResponse
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 * @spec openspec/changes/portal-broker-login-keeps-the-portal/specs/portal-broker-envelope-login/spec.md
	 */
	private function toBroker(string $org, string $provider, string $siteReturn, ?array $site): RedirectResponse {
		$params = ['org' => $org, 'provider' => $provider];
		// Only a resolved portal's slug rides along, never raw input; the
		// broker start resolves it again before echoing it.
		$slug = (string)($site['slug'] ?? '');
		if ($slug !== '') {
			$params['portal'] = $slug;
		}

		if ($siteReturn !== '') {
			$params['returnTo'] = $siteReturn;
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute(Application::APP_ID . '.brokerSession.start', $params),
			Http::STATUS_FOUND
		);
	}//end toBroker()

	/**
	 * The portal a login was started from, or null for none or an unknown one.
	 *
	 * @param string $portal The portal slug, or ''.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-signin-on-its-own-address/tasks.md#T3
	 */
	private function siteFor(string $portal): ?array {
		if ($portal === '') {
			return null;
		}

		return $this->portalFor(slug: $portal);
	}//end siteFor()

	/**
	 * A resolved portal's organisation slug, or ''.
	 *
	 * @param array<string, mixed>|null $site The resolved portal, or null.
	 *
	 * @return string
	 */
	private function organisationOf(?array $site): string {
		$organisation = ($site['organisation'] ?? null);
		if (is_string($organisation) === false) {
			return '';
		}

		return trim($organisation);
	}//end organisationOf()

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param PortalSessionService $session The session service.
	 * @param IConfig $config For the dev-login gate.
	 * @param PortalOrganisationConfigService $orgConfig Resolves per-org OIDC
	 *                                                   broker config
	 *                                                   (portal-oidc-broker-login).
	 * @param OidcClientService $oidc OIDC protocol mechanics
	 *                                (discovery, PKCE, token
	 *                                exchange, ID-token
	 *                                validation).
	 * @param OidcClaimMapperService $claimMapper Claim → identity + LoA →
	 *                                            trust mapping.
	 * @param OidcStateStoreService $stateStore Single-use state/nonce/PKCE storage.
	 * @param PortalAccountService $accounts Find-or-create `portalAccount`.
	 * @param IURLGenerator $urlGenerator Builds the callback redirect_uri
	 *                                    + the final SPA redirect.
	 * @param IUserSession $userSession The Nextcloud session, which IS the
	 *                                  credential for the `nextcloud` mode.
	 * @param PortalResolver $portals Resolves which portal is being signed
	 *                                into, so a mode it does not declare
	 *                                cannot be used against it.
	 * @param OrganisationLoginConfig|null $loginConfig The route per provider
	 *                                                  (signin-integriq-broker-login).
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalSessionService $session,
		private readonly IConfig $config,
		private readonly PortalOrganisationConfigService $orgConfig,
		private readonly OidcClientService $oidc,
		private readonly OidcClaimMapperService $claimMapper,
		private readonly OidcStateStoreService $stateStore,
		private readonly PortalAccountService $accounts,
		private readonly IURLGenerator $urlGenerator,
		private readonly IUserSession $userSession,
		private readonly PortalResolver $portals,
		private readonly ?OrganisationLoginConfig $loginConfig = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the caller's bearer to a subject.
	 *
	 * Fails closed on a bearer it cannot resolve (401). Reports the absence of
	 * a bearer as the ordinary anonymous state (200, `authenticated: false`) —
	 * see the body for why the distinction is load-bearing.
	 *
	 * @return JSONResponse 200 with the subject, 200 `authenticated: false`
	 *                      when no credential was offered, or 401 when one was
	 *                      offered and rejected.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T02
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T06
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function index(): JSONResponse {
		$authorization = $this->request->getHeader('Authorization');

		// ABSENCE IS NOT A FAILURE. Every anonymous visitor to a public portal
		// loads this endpoint once, sends no Authorization header, and is told
		// 401 — which the browser records as a console error on a page that is
		// working exactly as designed. The site renderer already treats the
		// answer as `authenticated: false` either way (`fetchSession()` reads
		// the FLAG, not the status), so the 401 bought nothing and cost a red
		// line in the console of every public page load.
		//
		// The distinction that matters is kept: a bearer that is PRESENT and
		// does not resolve — expired, tampered, revoked — is a real
		// authentication failure and still answers 401. Only "no credential
		// offered" is reported as the ordinary state it is.
		if ($authorization === '') {
			return new JSONResponse(['authenticated' => false]);
		}

		$subject = $this->session->resolveFromBearer($authorization);
		if ($subject === null) {
			return new JSONResponse(['authenticated' => false], Http::STATUS_UNAUTHORIZED);
		}

		$account = $this->accounts->findBySubjectRef(subjectRef: (string)$subject['subjectRef']);

		return new JSONResponse(
			[
				'authenticated' => true,
				'subjectRef' => $subject['subjectRef'],
				// Change site-header-names-the-person: the header greets the person by name, never by reference.
				'displayName' => $this->displayNameOf(account: $account, subjectRef: (string)$subject['subjectRef']),
				'audience' => $subject['audience'],
				'organisation' => $subject['organisation'],
				'trust' => $subject['trust'],
				// Change signin-eherkenning-branch: the header shows the branch in effect.
				'branch' => (string)($subject['branch'] ?? ''),
				'branchRestricted' => (($subject['branchRestricted'] ?? false) === true),
				// Change identity-profile-page T06: ask for an e-mail address when none is in use.
				'contactPrompt' => (new ContactAddressValues())->needsContactPrompt(account: $account),
				// Change the-account-names-the-audience-and-the-company: the company the person acts for.
				'organisationName' => $this->organisationNameOf(account: $account),
			] + $this->session->sessionTimes(subject: $subject)
		);
	}//end index()

	/**
	 * The name the site greets a signed-in person by, or '' when none is known.
	 *
	 * The account's display name, which provisioning or the broker set. A
	 * value equal to the subject reference or the identity number, or made of
	 * digits only (a BSN, a KvK number), is not a name and is never served:
	 * the header must not show an internal reference.
	 *
	 * @param array<string, mixed>|null $account    The person's portal account, or null.
	 * @param string                    $subjectRef The session's subject reference.
	 *
	 * @return string The name, or ''.
	 *
	 * @spec openspec/changes/site-header-names-the-person/specs/portaliq-cms/spec.md#requirement-the-header-must-name-the-signed-in-person-never-their-reference
	 * @spec openspec/changes/resident-sees-words-not-codes/specs/portaliq-cms/spec.md#requirement-the-header-must-never-name-a-person-by-a-number
	 */
	private function displayNameOf(?array $account, string $subjectRef): string {
		$name = trim((string)($account['displayName'] ?? ''));
		$identity = trim((string)($account['identityRef'] ?? ''));
		if ($name === '' || $name === $subjectRef || $name === $identity || ctype_digit($name) === true) {
			return '';
		}

		return $name;
	}//end displayNameOf()

	/**
	 * The company the signed-in person acts for, or '' when none is known.
	 *
	 * An app that invites a person for a company writes the company's display
	 * name as an `organisationName` claim on the account, in its own claim
	 * namespace (`claims.<appId>.organisationName`), next to the claim its
	 * collections scope by. The first non-empty one is served, trimmed and at
	 * most 200 characters. The portal's own organisation slug is a tenant,
	 * not this company, and is never used here.
	 *
	 * @param array<string, mixed>|null $account The person's portal account, or null.
	 *
	 * @return string The company's name, or ''.
	 *
	 * @spec openspec/changes/the-account-names-the-audience-and-the-company/specs/portal-identity-space/spec.md#requirement-the-session-names-the-company-the-person-acts-for
	 */
	private function organisationNameOf(?array $account): string {
		$claims = ($account['claims'] ?? null);
		if (is_array($claims) === false) {
			return '';
		}

		foreach ($claims as $appClaims) {
			$name = '';
			if (is_array($appClaims) === true && is_string($appClaims['organisationName'] ?? null) === true) {
				$name = trim($appClaims['organisationName']);
			}

			if ($name !== '') {
				return mb_substr($name, 0, 200);
			}
		}

		return '';
	}//end organisationNameOf()

	/**
	 * Mint a dev session (no real IdP). Gated — 404 unless dev-login is enabled.
	 *
	 * @param string $subjectRef The subject reference to embed.
	 * @param string $audience "supplier" or "client".
	 * @param string $organisation The tenant to scope to.
	 *
	 * @return JSONResponse 200 with a bearer token, or 404 when the gate is closed.
	 *
	 * The tightest anon rate limit of any session endpoint (design.md): a
	 * password-less mint must not become a brute-force oracle if a debug
	 * instance is ever exposed. `BruteForceProtection`'s delay is registered
	 * whenever the response is marked `throttle()`d below (the gate-closed
	 * 404 path).
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T1
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[BruteForceProtection(action: 'portaliq_dev_login')]
	public function devLogin(string $subjectRef = 'dev-supplier', string $audience = 'supplier', string $organisation = 'dev-org'): JSONResponse {
		if ($this->isDevLoginEnabled() === false) {
			$response = new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
			// Mark the attempt for Nextcloud's bruteforce throttler — probing
			// for a debug-only endpoint on a production instance is exactly
			// the abuse pattern BruteForceProtection exists to slow down.
			$response->throttle(['reason' => 'dev_login_disabled']);
			return $response;
		}

		// Dev-login is a password-less mint, so it carries the LOWEST assurance
		// level explicitly (contract v2, A3 — eIDAS-aligned trust vocabulary).
		$issued = $this->session->issueSession(
			subjectRef: $subjectRef,
			audience: $audience,
			organisation: $organisation,
			trust: 'low',
			roles: [$audience . ':read']
		);

		if ($issued === null) {
			// The auth edge fails closed when no dedicated jwt_signing_secret is
			// configured yet (portal-auth-edge-session-hardening) — never signs
			// with a placeholder.
			return new JSONResponse(['error' => 'not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse(
			[
				'token' => $issued['token'],
				'tokenType' => 'Bearer',
				'subjectRef' => $subjectRef,
				'audience' => $audience,
				'organisation' => $organisation,
			]
		);
	}//end devLogin()

	/**
	 * Start an OIDC broker login: resolves the org+provider config (fail
	 * closed if absent), generates `state`+`nonce`+PKCE, stores them
	 * single-use/TTL-bounded, and 302-redirects to the broker's authorization
	 * endpoint. Every failure — unknown org/provider, discovery unreachable,
	 * state-store write failure — returns the SAME generic error, never a
	 * redirect (design.md).
	 *
	 * The public site names the PORTAL, not the organisation: its sign-in
	 * links carry `?portal=<slug>` (`src/site/lib/authApi.js`). Without an
	 * `org` the organisation is the named portal's own `organisation` field,
	 * the tenant the portal belongs to (#802). An explicit `org` still wins,
	 * so the portal SPA's `?org=` links are unchanged.
	 *
	 * @param string $org The `?org=` slug to log in to.
	 * @param string $provider One of `digid|eherkenning|eidas|generic`.
	 * @param string $portal The `?portal=` slug the public site sends when it names no org.
	 * @param string $silent `1` asks the broker to sign in without a prompt
	 *                       (signin-session-idle-warning-and-sso D5).
	 * @param string $returnTo The site page to land on once signed in; only a page on the site route is kept.
	 *
	 * @return Response 302 to the broker, or the generic OIDC error.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T06
	 * @spec openspec/specs/supplier-portal/spec.md#oidc-start-builds-a-state-nonce-pkce-authorization-request
	 * @spec openspec/changes/archive/2026-09-29-signin-integriq-broker-login/design.md#d2-two-new-routes-and-the-spas-follow-the-route-field
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T07
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 *
	 * @no-admin-idor-exempt the lookup is unscoped because it MUST be: this is
	 * the anonymous entry point to a portal's login, so a caller with no
	 * session has to be able to name the org and provider it wants. Nothing
	 * about the resolved config reaches the caller — the response is either a
	 * 302 to the broker carrying only the public clientId, PKCE challenge,
	 * state and nonce, or oidcGenericError(). `clientSecret` is read only in
	 * the callback's server-to-server token exchange, never here.
	 *
	 * Enumeration is closed off by design rather than by scoping: an unknown
	 * org, an unknown provider, unreachable discovery and a failed state-store
	 * write all return the SAME generic error and never a redirect, so a caller
	 * cannot learn which orgs exist by trying them. AnonRateLimit(30/60) bounds
	 * the attempt rate on top of that.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function oidcStart(string $org = '', string $provider = '', string $portal = '', string $silent = '', string $returnTo = ''): Response {
		// The serving portal, resolved once: it names the organisation when
		// `?org=` is empty, and the login returns to it.
		$site = $this->siteFor(portal: $portal);
		if ($org === '') {
			$org = $this->organisationOf(site: $site);
		}

		// A provider the organisation routes to integriq's broker goes there
		// (signin-integriq-broker-login D2), so every sign-in link, the public
		// site's included, reaches the route the organisation chose.
		// A login started on the public site returns to the page it came
		// from, when that page is on the site route; anything else is dropped.
		$siteReturn = $this->siteReturn(returnTo: $returnTo);

		if ($this->loginConfig?->loginRouteFor(orgSlug: $org, provider: $provider) === 'broker') {
			return $this->toBroker(org: $org, provider: $provider, siteReturn: $siteReturn, site: $site);
		}

		// THE AUTHORISATION DECISION, MADE EXPLICITLY AND BEFORE ANY SECRET IS
		// TOUCHED. `resolveOidcConfig()` answers two different questions at
		// once — "may this org+provider start a login" and "give me the client
		// secret to do it" — and returns null for both. Splitting them means
		// the public entry point states its policy in one named predicate that
		// can be tested on its own, and the secret-bearing resolver is only
		// reached once that policy has said yes.
		if ($this->orgConfig->isLoginProviderAllowed(orgSlug: $org, provider: $provider) === false) {
			return $this->oidcGenericError();
		}

		$config = $this->orgConfig->resolveOidcConfig(orgSlug: $org, provider: $provider);
		if ($config === null) {
			return $this->oidcGenericError();
		}

		$endpoints = $this->oidc->discover(issuer: (string)$config['issuer']);
		if ($endpoints === null) {
			return $this->oidcGenericError();
		}

		$state = $this->oidc->generateToken();
		$nonce = $this->oidc->generateToken();
		$pkce = $this->oidc->generatePkce();
		// A silent start asks the broker for no prompt (signin-session-idle-warning-and-sso D5).
		$prompt = '';
		if ($silent === '1') {
			$prompt = 'none';
		}

		$stored = $this->stateStore->create(
			state: $state,
			nonce: $nonce,
			codeVerifier: $pkce['verifier'],
			org: $org,
			provider: $provider,
			returnTo: $this->returnAddress(siteReturn: $siteReturn, site: $site),
			silent: ($prompt === 'none')
		);
		if ($stored === false) {
			return $this->oidcGenericError();
		}

		$url = $this->oidc->buildAuthorizationUrl(
			authorizeEndpoint: $endpoints['authorization_endpoint'],
			clientId: (string)$config['clientId'],
			redirectUri: $this->oidcRedirectUri(),
			scopes: (array)$config['scopes'],
			state: $state,
			nonce: $nonce,
			codeChallenge: $pkce['challenge'],
			prompt: $prompt
		);

		// Explicit 302 (design.md) — RedirectResponse's own default is 303.
		return new RedirectResponse($url, Http::STATUS_FOUND);
	}//end oidcStart()


	/**
	 * OIDC broker callback: consumes the single-use `state` (CSRF/replay
	 * guard), exchanges the code, FULLY validates the ID token (issuer,
	 * audience, nonce, expiry, RS256 signature via cached JWKS — {@see
	 * OidcClientService::verifyIdToken()}), maps claims + LoA, finds-or-
	 * creates the `portalAccount`, and mints the EXISTING HS256 portal
	 * session via `PortalSessionService::issueSession()`.
	 *
	 * ANY failure at ANY step returns the IDENTICAL generic error and mints
	 * NO session (design.md, ADR-005) — no response distinguishes which
	 * check failed.
	 *
	 * @param string $state The OIDC `state` returned by the broker.
	 * @param string $code The authorization code.
	 * @param string $error An error the broker itself reported (e.g. `access_denied`).
	 *
	 * @return Response 302 to the SPA with the minted bearer, or the generic OIDC error.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T07
	 * @spec openspec/specs/supplier-portal/spec.md#oidc-callback-validates-the-id-token-and-fails-closed-on-every-error
	 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T02
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T08
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- one fail-closed guard
	 * per step of the OIDC flow (state, config, discovery, exchange, ID-token
	 * validation, claim mapping, account resolution, session issuance), every
	 * one returning the IDENTICAL generic error (ADR-005, design.md);
	 * collapsing them would trade auditability for a score, mirroring
	 * PortalSessionService's identical rationale.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      -- same rationale.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function oidcCallback(string $state = '', string $code = '', string $error = ''): Response {
		if ($error !== '') {
			return $this->brokerErrorAnswer(state: $state, error: $error);
		}

		if ($state === '' || $code === '') {
			return $this->oidcGenericError();
		}

		$pending = $this->stateStore->consume(state: $state);
		// A row written for the integriq broker route cannot complete an OIDC
		// login (signin-integriq-broker-login, design D3).
		if ($pending === null || $pending['route'] !== 'oidc' || $pending['codeVerifier'] === '') {
			return $this->oidcGenericError();
		}

		$config = $this->orgConfig->resolveOidcConfig(orgSlug: $pending['org'], provider: $pending['provider']);
		if ($config === null) {
			return $this->oidcGenericError();
		}

		$endpoints = $this->oidc->discover(issuer: (string)$config['issuer']);
		if ($endpoints === null) {
			return $this->oidcGenericError();
		}

		$tokenResponse = $this->oidc->exchangeCode(
			tokenEndpoint: $endpoints['token_endpoint'],
			code: $code,
			codeVerifier: $pending['codeVerifier'],
			clientId: (string)$config['clientId'],
			clientSecret: (string)$config['clientSecret'],
			redirectUri: $this->oidcRedirectUri()
		);
		$idToken = (string)($tokenResponse['id_token'] ?? '');
		if ($idToken === '') {
			return $this->oidcGenericError();
		}

		$claims = $this->oidc->verifyIdToken(
			idToken: $idToken,
			jwksUri: $endpoints['jwks_uri'],
			issuer: (string)$config['issuer'],
			clientId: (string)$config['clientId'],
			expectedNonce: $pending['nonce']
		);
		if ($claims === null) {
			return $this->oidcGenericError();
		}

		$mapped = $this->claimMapper->mapClaims(claims: $claims, config: $config);
		if ($mapped === null) {
			return $this->oidcGenericError();
		}

		$account = $this->accounts->findOrCreate(
			identityType: $mapped['identityType'],
			identityRef: $mapped['identityRef'],
			organisation: $pending['org'],
			audience: $mapped['audience'],
			subjectRefOverride: $mapped['subjectRef'],
			verifiedEmail: $this->verifiedEmailOf(claims: $claims)
		);
		if ($account === null) {
			return $this->oidcGenericError();
		}

		$trust = $this->claimMapper->mapLoaToTrust(claims: $claims, config: $config);
		// The account's own audience wins over the claim map's (the-account-names-the-audience-and-the-company).
		$audience = (string)($account['audience'] ?? $mapped['audience']);
		$issued   = $this->session->issueSession(
			subjectRef: $account['subjectRef'],
			audience: $audience,
			organisation: $pending['org'],
			trust: $trust,
			roles: [$audience . ':read'],
			branch: (string)($mapped['branch'] ?? ''),
			provider: $pending['provider']
		);
		if ($issued === null) {
			return $this->oidcGenericError();
		}

		$returnTo = $this->portalReturnTo();
		if ($pending['returnTo'] !== '') {
			$returnTo = $pending['returnTo'];
		}

		// The bearer travels in the URL FRAGMENT, never a query string — a
		// fragment is never sent to the server (no access/Referer-header
		// leak) and never appears in server logs.
		$redirectUrl = $this->urlGenerator->getAbsoluteURL($returnTo) . '#token=' . rawurlencode($issued['token']);

		// Explicit 302 (design.md) — RedirectResponse's own default is 303.
		return new RedirectResponse($redirectUrl, Http::STATUS_FOUND);
	}//end oidcCallback()

	/**
	 * The answer to a broker that returned an error instead of a code. A silent
	 * attempt answered with "the resident must interact" lands on the portal's
	 * login screen with no message and no token; every other error, and any
	 * error on a row that was not silent, keeps the one generic failure. The
	 * state is consumed either way, so it can never be replayed.
	 *
	 * @param string $state The OIDC `state` returned by the broker.
	 * @param string $error The error the broker reported.
	 *
	 * @return Response
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T08
	 */
	private function brokerErrorAnswer(string $state, string $error): Response {
		$pending = $this->stateStore->consume(state: $state);
		if ($pending === null || ($pending['silent'] ?? false) !== true || in_array($error, self::SILENT_LOGIN_ERRORS, true) === false) {
			return $this->oidcGenericError();
		}

		$returnTo = $this->portalReturnTo();
		if ($pending['returnTo'] !== '') {
			$returnTo = $pending['returnTo'];
		}

		return new RedirectResponse($this->urlGenerator->getAbsoluteURL($returnTo), Http::STATUS_FOUND);
	}//end brokerErrorAnswer()

	/**
	 * The address the broker itself says it verified, or ''.
	 *
	 * REQ-PIS-002 of portal-identity-space: an account provisioned before
	 * a login is matched on its identity reference first, and only then on
	 * an address the broker says it verified. An unverified address is not
	 * passed on at all, so it can never claim a waiting account.
	 *
	 * @param array<string, mixed> $claims The verified ID token claims.
	 *
	 * @return string
	 */
	private function verifiedEmailOf(array $claims): string {
		$emailIsVerified = (($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true');
		if ($emailIsVerified === true && is_string(($claims['email'] ?? null)) === true) {
			return (string)$claims['email'];
		}

		return '';
	}//end verifiedEmailOf()

	/**
	 * The redirect_uri this RP presents to every broker — MUST be identical
	 * at `start` and at `callback` (most brokers reject a mismatch).
	 *
	 * @return string
	 */
	private function oidcRedirectUri(): string {
		return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.session.oidcCallback');
	}//end oidcRedirectUri()

	/**
	 * The ONE generic OIDC failure response — reused by every failure branch
	 * of `oidcStart()`/`oidcCallback()` so no response can ever distinguish
	 * which check failed (design.md, ADR-005 — no oracle).
	 *
	 * @return JSONResponse 400 with a generic error code.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
	 */
	private function oidcGenericError(): JSONResponse {
		return new JSONResponse(['error' => self::OIDC_GENERIC_ERROR], Http::STATUS_BAD_REQUEST);
	}//end oidcGenericError()

	/**
	 * Sign in with a Nextcloud account — the `nextcloud` authentication mode.
	 *
	 * THE MODE WAS ALREADY OFFERED AND LED NOWHERE. `signInRoutes()` has always
	 * rendered "Inloggen met uw account" for a portal declaring `nextcloud`,
	 * pointing at `/portal/api/session/nextcloud`. No such route existed, so the
	 * button 404'd: a sign-in option that looks identical to a working one right
	 * up to the moment somebody uses it.
	 *
	 * WHY THIS IS NOT `#[PublicPage]`. It is the one endpoint here that WANTS a
	 * Nextcloud session — that session is the credential. An anonymous caller is
	 * sent to Nextcloud's own login form and comes back; this app never sees a
	 * password, and there is no password field of ours to attack.
	 *
	 * THE PORTAL MUST DECLARE THE MODE. A portal that does not offer
	 * `nextcloud` cannot be entered through it, even by a logged-in user who
	 * types the URL. A mode that is enforced only by which buttons are drawn is
	 * not enforced at all.
	 *
	 * THE ACCOUNT MUST ALREADY EXIST. This mints a session for an existing
	 * `portalAccount` whose `subjectRef` is the Nextcloud user id — it does not
	 * create one. Auto-creating would make every account on the instance a
	 * citizen of every portal that enables this mode.
	 *
	 * @param string $portal   The portal slug to sign in to.
	 * @param string $returnTo Where to send the browser afterwards.
	 *
	 * @return Response A redirect carrying the bearer in the URL fragment.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function nextcloud(string $portal = '', string $returnTo = ''): Response {
		$uid = $this->currentNextcloudUid();
		if ($uid === '') {
			// Not signed in to Nextcloud yet: hand the visitor to the platform's
			// own login form and come back here. The password is Nextcloud's
			// business, never ours.
			return new RedirectResponse(
				$this->urlGenerator->linkToRoute(
					'core.login.showLoginForm',
					['redirect_url' => $this->request->getRequestUri()]
				),
				Http::STATUS_FOUND
			);
		}

		$site = $this->portalFor(slug: $portal);
		if ($site === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$modes = ($site['authentication']['modes'] ?? []);
		if (is_array($modes) === false || in_array('nextcloud', $modes, true) === false) {
			// Refused with the same shape as an unknown portal: which modes a
			// portal offers is not something an anonymous prober should be able
			// to enumerate one 403 at a time.
			return new JSONResponse(['error' => 'mode_not_offered'], Http::STATUS_NOT_FOUND);
		}

		$account = $this->accounts->findBySubjectRef(subjectRef: $uid);
		if ($account === null || ($account['status'] ?? 'active') !== 'active') {
			return new JSONResponse(['error' => 'no_portal_account'], Http::STATUS_FORBIDDEN);
		}

		$audience = (string)($account['audience'] ?? 'client');
		$issued = $this->session->issueSession(
			subjectRef: $uid,
			audience: $audience,
			organisation: (string)($account['organisation'] ?? 'dev-org'),
			// A username and password on this instance is an eIDAS-'low'
			// assurance, the same as dev-login and deliberately BELOW DigiD:
			// a portal gating on trust must not be fooled by the fact that
			// this login felt more effortful.
			trust: 'low',
			roles: [$audience . ':read']
		);

		if ($issued === null) {
			return new JSONResponse(['error' => 'not_configured'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		// Same hand-off as the OIDC callback: the bearer travels in the URL
		// FRAGMENT, which is never sent to a server and never reaches a log.
		$target = $returnTo;
		if ($target === '') {
			$target = '/apps/portaliq/site?portal=' . rawurlencode((string)($site['slug'] ?? ''));
		}

		return new RedirectResponse(
			$this->urlGenerator->getAbsoluteURL($target) . '#token=' . rawurlencode($issued['token']),
			Http::STATUS_FOUND
		);
	}//end nextcloud()

	/**
	 * The signed-in Nextcloud user id, or '' when there is none.
	 *
	 * @return string The uid.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	private function currentNextcloudUid(): string {
		$user = $this->userSession->getUser();

		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end currentNextcloudUid()

	/**
	 * Resolve the portal being signed in to, by slug or by host.
	 *
	 * @param string $slug The requested slug, or ''.
	 *
	 * @return array<string, mixed>|null The portal record, or null.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	private function portalFor(string $slug): ?array {
		return $this->portals->resolve(request: $this->request, portalSlug: $slug);
	}//end portalFor()

	/**
	 * End the client session: resolves the caller's own bearer and marks its
	 * `portalSession` record revoked, so `resolveFromBearer()` rejects it on
	 * any subsequent request, even before its natural expiry. Always responds
	 * `{ok: true}` — an already-invalid or unknown bearer is not itself an
	 * error (the client's local token is dropped regardless per App.jsx).
	 *
	 * A session minted through an OIDC broker that announces an
	 * `end_session_endpoint` also gets `logoutUrl`, the broker's sign-out
	 * address, which the SPA follows (signin-session-idle-warning-and-sso D6).
	 *
	 * @return JSONResponse 200.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/archive/2026-10-09-portal-auth-edge-session-hardening/tasks.md#3.1
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function logout(): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject === null) {
			return new JSONResponse(['ok' => true]);
		}

		$this->session->revoke((string)($subject['jti'] ?? ''));

		$logoutUrl = $this->brokerLogoutUrl(subject: $subject);
		if ($logoutUrl === '') {
			return new JSONResponse(['ok' => true]);
		}

		return new JSONResponse(['ok' => true, 'logoutUrl' => $logoutUrl]);
	}//end logout()

	/**
	 * The broker's sign-out address for a session minted through it, with
	 * `client_id` and `post_logout_redirect_uri` (OpenID Connect RP-Initiated
	 * Logout 1.0), or '' when the session has no provider, the provider has
	 * no config, or the broker announces no `end_session_endpoint`. Portaliq
	 * keeps no ID token, so it sends no `id_token_hint`.
	 *
	 * @param array<string, mixed> $subject The resolved session.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#8
	 */
	private function brokerLogoutUrl(array $subject): string {
		$provider = (string)($subject['provider'] ?? '');
		// An e-mail link session has no broker to sign out of (REQ-IWI-011).
		if ($provider === '' || $provider === EmailLinkSetting::MODE) {
			return '';
		}

		$config = $this->orgConfig->resolveOidcConfig(orgSlug: (string)($subject['organisation'] ?? ''), provider: $provider);
		if ($config === null) {
			return '';
		}

		$endSession = (string)($this->oidc->discover(issuer: (string)$config['issuer'])['end_session_endpoint'] ?? '');
		if ($endSession === '') {
			return '';
		}

		$separator = '?';
		if (str_contains($endSession, '?') === true) {
			$separator = '&';
		}

		$query = http_build_query(
			[
				'client_id' => (string)$config['clientId'],
				'post_logout_redirect_uri' => $this->urlGenerator->getAbsoluteURL($this->portalReturnTo()),
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		return $endSession . $separator . $query;
	}//end brokerLogoutUrl()

	/**
	 * Rotate the caller's bearer within the absolute session lifetime cap
	 * (portal-session-hardening-v2). A valid, unexpired, not-yet-revoked
	 * bearer gets a NEW bearer with a NEW `jti`; the OLD bearer is revoked
	 * (rotation, not a second live token). Fails closed with the SAME generic
	 * 401 on every rejection — revoked, expired, malformed, past the absolute
	 * cap, or the edge not yet configured — never distinguishing why.
	 *
	 * @return JSONResponse 200 with the new bearer, or 401 on any rejection.
	 *
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 * @spec openspec/specs/supplier-portal/spec.md#session-refresh-rotates-the-token-within-an-absolute-cap
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function refresh(): JSONResponse {
		$issued = $this->session->refreshSession($this->request->getHeader('Authorization'));
		if ($issued === null) {
			return new JSONResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		return new JSONResponse(
			[
				'token' => $issued['token'],
				'tokenType' => 'Bearer',
				'expiresAt' => (int)($issued['expiresAt'] ?? 0),
				'hardExpiresAt' => (int)($issued['hardExpiresAt'] ?? 0),
				'idleTimeout' => (int)($issued['idleTimeout'] ?? 0),
			]
		);
	}//end refresh()

	/**
	 * Whether the dev-login gate is open: NC debug mode, or an explicit app flag.
	 *
	 * @return bool
	 */
	private function isDevLoginEnabled(): bool {
		if ($this->config->getSystemValueBool('debug', false) === true) {
			return true;
		}

		return $this->config->getAppValue(Application::APP_ID, 'dev_login_enabled', 'no') === 'yes';
	}//end isDevLoginEnabled()
}//end class
