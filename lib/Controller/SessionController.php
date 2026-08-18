<?php

/**
 * Portaliq Session Controller
 *
 * The public auth-edge HTTP surface for the portal SPA. `index()` resolves the
 * caller's bearer to a server-derived subject (fail-closed). `devLogin()` mints
 * a session WITHOUT a real IdP — it is gated behind Nextcloud debug mode or an
 * explicit app flag so it can never issue tokens in production; it exists so the
 * portal is exercisable before the eHerkenning / DigiD broker (dormant, awaiting
 * OpenConnector) is wired. `logout()` ends the client session.
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
 * @spec openspec/changes/contract-v2/tasks.md#T1
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T06
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\OidcClientService;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
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
	 * Where an OIDC callback lands in the SPA when no `returnTo` was stored:
	 * the portal page's OWN route, resolved through the URL generator so it
	 * carries the app's web-root (`/apps/portaliq/portal`). A bare `/portal`
	 * literal resolved to the Nextcloud ROOT (`/portal`), which 404s — the
	 * portal is an app page, so every OIDC login landed on "Page not found".
	 *
	 * @return string
	 */
	private function portalReturnTo(): string {
		return $this->urlGenerator->linkToRoute(Application::APP_ID . '.portalPage.index');
	}//end portalReturnTo()

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

		return new JSONResponse(
			[
				'authenticated' => true,
				'subjectRef' => $subject['subjectRef'],
				'audience' => $subject['audience'],
				'organisation' => $subject['organisation'],
				'trust' => $subject['trust'],
			]
		);
	}//end index()

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
	 * @spec openspec/changes/contract-v2/tasks.md#T1
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
	 * @param string $org The `?org=` slug to log in to.
	 * @param string $provider One of `digid|eherkenning|eidas|generic`.
	 *
	 * @return Response 302 to the broker, or the generic OIDC error.
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T06
	 * @spec openspec/specs/supplier-portal/spec.md#oidc-start-builds-a-state-nonce-pkce-authorization-request
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
	public function oidcStart(string $org = '', string $provider = ''): Response {
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

		$stored = $this->stateStore->create(
			state: $state,
			nonce: $nonce,
			codeVerifier: $pkce['verifier'],
			org: $org,
			provider: $provider,
			returnTo: $this->portalReturnTo()
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
			codeChallenge: $pkce['challenge']
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
		if ($state === '' || $code === '' || $error !== '') {
			return $this->oidcGenericError();
		}

		$pending = $this->stateStore->consume(state: $state);
		if ($pending === null) {
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
			subjectRefOverride: $mapped['subjectRef']
		);
		if ($account === null) {
			return $this->oidcGenericError();
		}

		$trust = $this->claimMapper->mapLoaToTrust(claims: $claims, config: $config);
		$issued = $this->session->issueSession(
			subjectRef: $account['subjectRef'],
			audience: $mapped['audience'],
			organisation: $pending['org'],
			trust: $trust,
			roles: [$mapped['audience'] . ':read']
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
	 * End the client session: resolves the caller's own bearer and marks its
	 * `portalSession` record revoked, so `resolveFromBearer()` rejects it on
	 * any subsequent request, even before its natural expiry. Always responds
	 * `{ok: true}` — an already-invalid or unknown bearer is not itself an
	 * error (the client's local token is dropped regardless per App.jsx).
	 *
	 * @return JSONResponse 200.
	 *
	 * @spec openspec/changes/supplier-portal/tasks.md#T02
	 * @spec openspec/changes/portal-auth-edge-session-hardening/tasks.md#3.1
	 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T05
	 */
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
	 * @contract tests/Unit/Controller/SessionControllerTest.php — asserts that
	 *           this EXCHANGES a Nextcloud session for a portal one rather than
	 *           creating one from nothing. A first attempt at that test asserted
	 *           `#[PublicPage]` and failed, correctly: public here would mint a
	 *           portal session for a caller who proved nothing.
	 *
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
		$target = '/apps/portaliq/site?portal=' . rawurlencode((string)($site['slug'] ?? ''));
		if ($returnTo !== '') {
			$target = $returnTo;
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
	 */
	private function portalFor(string $slug): ?array {
		return $this->portals->resolve(request: $this->request, portalSlug: $slug);
	}//end portalFor()


	/**
	 * End the portal session the bearer names.
	 *
	 * Public because a visitor whose token has already expired must still be
	 * able to sign out: refusing the request would leave the browser holding a
	 * token it cannot revoke.
	 *
	 * @return JSONResponse Always `{ok: true}` — a session that was already
	 *                      gone is a successful sign-out, not an error.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function logout(): JSONResponse {
		$subject = $this->session->resolveFromBearer($this->request->getHeader('Authorization'));
		if ($subject !== null) {
			$this->session->revoke((string)($subject['jti'] ?? ''));
		}

		return new JSONResponse(['ok' => true]);
	}//end logout()

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
