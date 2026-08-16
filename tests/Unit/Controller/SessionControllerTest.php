<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SessionController;
use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\OidcClientService;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * portal-controller-http-test-coverage: the three HTTP-facing behaviours
 * `supplier-portal` T02 describes as DONE but never turned into a regression
 * test — `GET /portal/api/session` resolve (both branches), the dev-login
 * gate (open vs 404-closed, per `src/portal/App.jsx`'s own comment about the
 * production posture), and logout's real revocation
 * (portal-auth-edge-session-hardening) rather than a static `{ok: true}`.
 *
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#2.1
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#2.2
 * @spec openspec/changes/portal-controller-http-test-coverage/tasks.md#2.3
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T03
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T12
 */
class SessionControllerTest extends TestCase {

	private const SUBJECT = [
		'subjectRef' => 's1',
		'audience' => 'supplier',
		'organisation' => 'org-1',
		'trust' => 'low',
		'roles' => [],
		'jti' => 'jti-1',
	];

	public function testIndexReturnsSubjectShapeForAValidBearer(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$response = $this->controller(session: $session)->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['authenticated']);
		$this->assertSame('s1', $data['subjectRef']);
		$this->assertSame('supplier', $data['audience']);
		$this->assertSame('org-1', $data['organisation']);
		$this->assertSame('low', $data['trust']);

	}//end testIndexReturnsSubjectShapeForAValidBearer()

	public function testIndexReturns401ForAnInvalidBearer(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(null);

		$response = $this->controller(session: $session)->index();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['authenticated']);

	}//end testIndexReturns401ForAnInvalidBearer()

	/**
	 * The anonymous visitor is the COMMON case on a public portal, not an error.
	 *
	 * Every public page load hit this endpoint with no Authorization header and
	 * got 401, which the browser records as a console error on a page working
	 * exactly as designed. The renderer never cared — `fetchSession()` reads the
	 * `authenticated` FLAG, not the status — so the 401 bought nothing.
	 *
	 * Asserted as a PAIR with the test above, because "no credential offered"
	 * and "credential offered and rejected" must not collapse into one answer:
	 * a change that returned 200 to both would pass this test alone while
	 * silently retiring the failure signal for an expired or tampered bearer.
	 */
	public function testIndexReturns200AuthenticatedFalseWhenNoBearerIsOffered(): void {
		$session = $this->createMock(PortalSessionService::class);
		// resolveFromBearer must not even be consulted — there is nothing to
		// resolve, and calling it would make an absent header indistinguishable
		// from a rejected one at the service layer too.
		$session->expects($this->never())->method('resolveFromBearer');

		$response = $this->controller(session: $session, authorization: '')->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['authenticated']);
		// No subject fields may leak into the anonymous answer.
		$this->assertArrayNotHasKey('subjectRef', $response->getData());

	}//end testIndexReturns200AuthenticatedFalseWhenNoBearerIsOffered()

	public function testDevLoginReturns404WhenGateIsClosed(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('getAppValue')->willReturn('no');

		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');

		$response = $this->controller(session: $session, config: $config)->devLogin();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		// Marked for Nextcloud's bruteforce throttler (portal-session-hardening-v2,
		// T05) — probing for a debug-only endpoint on a closed instance is the
		// abuse pattern BruteForceProtection exists to slow down.
		$this->assertTrue($response->isThrottled());

	}//end testDevLoginReturns404WhenGateIsClosed()

	public function testDevLoginMintsATokenWhenDebugModeIsOn(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueSession')->willReturn(['token' => 'signed.jwt.token', 'jti' => 'jti-1']);

		$response = $this->controller(session: $session, config: $config)->devLogin(
			subjectRef: 'dev-supplier',
			audience: 'supplier',
			organisation: 'dev-org'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('signed.jwt.token', $data['token']);
		$this->assertSame('Bearer', $data['tokenType']);

	}//end testDevLoginMintsATokenWhenDebugModeIsOn()

	public function testDevLoginMintsATokenWhenTheAppFlagIsExplicitlyEnabled(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('getAppValue')->willReturn('yes');

		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueSession')->willReturn(['token' => 't', 'jti' => 'j']);

		$response = $this->controller(session: $session, config: $config)->devLogin();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testDevLoginMintsATokenWhenTheAppFlagIsExplicitlyEnabled()

	public function testDevLoginReturns503WhenNoDedicatedSecretIsConfigured(): void {
		// portal-auth-edge-session-hardening: the edge fails closed rather
		// than falling back to a system/shared secret.
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(true);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueSession')->willReturn(null);

		$response = $this->controller(session: $session, config: $config)->devLogin();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());

	}//end testDevLoginReturns503WhenNoDedicatedSecretIsConfigured()

	public function testLogoutRevokesTheCallersOwnSessionAndAlwaysReturnsOk(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);
		$session->expects($this->once())->method('revoke')->with('jti-1');

		$response = $this->controller(session: $session)->logout();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['ok']);

	}//end testLogoutRevokesTheCallersOwnSessionAndAlwaysReturnsOk()

	public function testLogoutOnAnAlreadyInvalidBearerIsNotAnError(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(null);
		$session->expects($this->never())->method('revoke');

		$response = $this->controller(session: $session)->logout();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['ok']);

	}//end testLogoutOnAnAlreadyInvalidBearerIsNotAnError()

	public function testRefreshReturnsANewBearerForAValidSession(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('refreshSession')->willReturn(['token' => 'new.signed.jwt', 'jti' => 'jti-2']);

		$response = $this->controller(session: $session)->refresh();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('new.signed.jwt', $data['token']);
		$this->assertSame('Bearer', $data['tokenType']);

	}//end testRefreshReturnsANewBearerForAValidSession()

	public function testRefreshReturns401OnAnyRejection(): void {
		// Fail-closed: revoked, expired, malformed, past-the-cap, or
		// unconfigured all collapse to the SAME null → the SAME generic 401.
		$session = $this->createMock(PortalSessionService::class);
		$session->method('refreshSession')->willReturn(null);

		$response = $this->controller(session: $session)->refresh();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testRefreshReturns401OnAnyRejection()

	// -- portal-oidc-broker-login (T06/T07/T12) -----------------------------

	public function testOidcStartFailsClosedWhenTheOrgProviderIsUnconfigured(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('resolveOidcConfig')->willReturn(null);

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->expects($this->never())->method('discover');

		$response = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc)
			->oidcStart(org: 'gemeente-x', provider: 'eherkenning');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('oidc_failed', $response->getData()['error']);

	}//end testOidcStartFailsClosedWhenTheOrgProviderIsUnconfigured()

	public function testOidcStartFailsClosedWhenDiscoveryIsUnreachable(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
		$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn(null);

		$response = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc)
			->oidcStart(org: 'gemeente-x', provider: 'eherkenning');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('oidc_failed', $response->getData()['error']);

	}//end testOidcStartFailsClosedWhenDiscoveryIsUnreachable()

	public function testOidcStartFailsClosedWhenTheStateCannotBeStored(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
		$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn(['authorization_endpoint' => 'https://broker.example/authorize', 'token_endpoint' => 'https://broker.example/token', 'jwks_uri' => 'https://broker.example/jwks']);
		$oidc->method('generateToken')->willReturn('tok');
		$oidc->method('generatePkce')->willReturn(['verifier' => 'v', 'challenge' => 'c']);

		$stateStore = $this->createMock(OidcStateStoreService::class);
		$stateStore->method('create')->willReturn(false);

		$response = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc, stateStore: $stateStore)
			->oidcStart(org: 'gemeente-x', provider: 'eherkenning');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('oidc_failed', $response->getData()['error']);

	}//end testOidcStartFailsClosedWhenTheStateCannotBeStored()

	/**
	 * The policy predicate GATES, and it gates BEFORE any secret is resolved.
	 *
	 * The pair to the redirect test below. Splitting the authorisation
	 * decision out of `resolveOidcConfig()` is only worth anything if the
	 * decision is actually consulted — a refactor that named the predicate and
	 * then ignored it would pass every other test in this class, because the
	 * resolver still fails closed on its own.
	 *
	 * `resolveOidcConfig` is asserted NEVER called: a caller the policy has
	 * already refused must not reach the method that reads the client secret.
	 */
	public function testOidcStartRefusesBeforeResolvingAnySecretWhenThePolicyDeclines(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(false);
		$orgConfig->expects($this->never())->method('resolveOidcConfig');

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->expects($this->never())->method('discover');

		$response = $this->controller(
			session: $this->createMock(PortalSessionService::class),
			orgConfig: $orgConfig,
			oidc: $oidc
		)->oidcStart(org: 'gemeente-x', provider: 'eherkenning');

		// The SAME generic error every other failure returns — an unknown org
		// and a declined one must be indistinguishable, or the endpoint
		// becomes an existence oracle for which tenants exist here.
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testOidcStartRefusesBeforeResolvingAnySecretWhenThePolicyDeclines()


	public function testOidcStartRedirectsToTheBrokerWithStateNonceAndPkce(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
		$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn(['authorization_endpoint' => 'https://broker.example/authorize', 'token_endpoint' => 'https://broker.example/token', 'jwks_uri' => 'https://broker.example/jwks']);
		$oidc->method('generateToken')->willReturnOnConsecutiveCalls('state-1', 'nonce-1');
		$oidc->method('generatePkce')->willReturn(['verifier' => 'verifier-1', 'challenge' => 'challenge-1']);
		$oidc->expects($this->once())->method('buildAuthorizationUrl')->with(
			'https://broker.example/authorize',
			'rp-client-1',
			$this->anything(),
			['openid'],
			'state-1',
			'nonce-1',
			'challenge-1'
		)->willReturn('https://broker.example/authorize?state=state-1&nonce=nonce-1&code_challenge=challenge-1');

		$stateStore = $this->createMock(OidcStateStoreService::class);
		$stateStore->expects($this->once())->method('create')->with('state-1', 'nonce-1', 'verifier-1', 'gemeente-x', 'eherkenning', $this->anything())->willReturn(true);

		$response = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc, stateStore: $stateStore)
			->oidcStart(org: 'gemeente-x', provider: 'eherkenning');

		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());

	}//end testOidcStartRedirectsToTheBrokerWithStateNonceAndPkce()

	/**
	 * @return array<string, array{0: callable}>
	 */
	public static function oidcCallbackFailureProvider(): array {
		return [
			'missing state' => [static fn (SessionControllerTest $t) => ['args' => ['state' => '', 'code' => 'c']]],
			'missing code' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => '']]],
			'broker reported error' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c', 'error' => 'access_denied']]],
			'unknown/reused state' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => null]],
			'unconfigured provider' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => null]],
			'discovery unreachable' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => null]],
			'token exchange failed' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => null]],
			'no id_token in response' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => ['access_token' => 'a']]],
			'id token verification failed (covers nonce/aud/iss/sig/exp — matrix in OidcClientServiceTest)' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => ['id_token' => 'x.y.z'], 'verifyIdToken' => null]],
			'unmappable claims' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => ['id_token' => 'x.y.z'], 'verifyIdToken' => ['sub' => 'abc'], 'mapClaims' => null]],
			'account resolution failed' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => ['id_token' => 'x.y.z'], 'verifyIdToken' => ['sub' => 'abc'], 'mapClaims' => $t->mappedFixture(), 'findOrCreate' => null]],
			'session issuance failed' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => ['id_token' => 'x.y.z'], 'verifyIdToken' => ['sub' => 'abc'], 'mapClaims' => $t->mappedFixture(), 'findOrCreate' => ['subjectRef' => 'sub-1', 'isNew' => true], 'issueSession' => null]],
		];

	}//end oidcCallbackFailureProvider()

	/**
	 * Security invariant: every one of the callback's distinct failure modes
	 * — replay/unknown state, unconfigured provider, discovery/exchange/
	 * verification failure, unmappable claims, account/session mint failure
	 * — returns the EXACT SAME generic error (status + body), never a
	 * distinguishing detail (no oracle).
	 *
	 * @dataProvider oidcCallbackFailureProvider
	 */
	public function testOidcCallbackEveryFailureModeIsTheIdenticalGenericError(callable $scenario): void {
		$s = $scenario($this);

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('resolveOidcConfig')->willReturn(array_key_exists('orgConfig', $s) ? $s['orgConfig'] : $this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn(array_key_exists('discover', $s) ? $s['discover'] : $this->discoveryFixture());
		$oidc->method('exchangeCode')->willReturn(array_key_exists('exchangeCode', $s) ? $s['exchangeCode'] : ['id_token' => 'x.y.z']);
		$oidc->method('verifyIdToken')->willReturn(array_key_exists('verifyIdToken', $s) ? $s['verifyIdToken'] : ['sub' => 'abc']);

		$claimMapper = $this->createMock(OidcClaimMapperService::class);
		$claimMapper->method('mapClaims')->willReturn(array_key_exists('mapClaims', $s) ? $s['mapClaims'] : $this->mappedFixture());
		$claimMapper->method('mapLoaToTrust')->willReturn('low');

		$stateStore = $this->createMock(OidcStateStoreService::class);
		$stateStore->method('consume')->willReturn(array_key_exists('stateConsume', $s) ? $s['stateConsume'] : $this->pendingFixture());

		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findOrCreate')->willReturn(array_key_exists('findOrCreate', $s) ? $s['findOrCreate'] : ['subjectRef' => 'sub-1', 'isNew' => true]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('issueSession')->willReturn(array_key_exists('issueSession', $s) ? $s['issueSession'] : ['token' => 't', 'jti' => 'j']);

		$controller = $this->controller(
			session: $session,
			orgConfig: $orgConfig,
			oidc: $oidc,
			claimMapper: $claimMapper,
			stateStore: $stateStore,
			accounts: $accounts
		);

		$args = $s['args'];
		$response = $controller->oidcCallback(state: ($args['state'] ?? ''), code: ($args['code'] ?? ''), error: ($args['error'] ?? ''));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'oidc_failed'], $response->getData());

	}//end testOidcCallbackEveryFailureModeIsTheIdenticalGenericError()

	/**
	 * Happy path: the subjectRef minted is EXACTLY the claim-mapper's
	 * server-derived value (never taken from a request parameter), the trust
	 * comes from the LoA mapper, and the redirect carries the bearer in the
	 * URL FRAGMENT (never a query string — no Referer/log leak).
	 */
	public function testOidcCallbackMintsASessionAndRedirectsWithTheBearerInTheFragment(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
		$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn($this->discoveryFixture());
		$oidc->method('exchangeCode')->willReturn(['id_token' => 'x.y.z']);
		$oidc->method('verifyIdToken')->willReturn(['sub' => 'broker-subject-abc', 'acr' => 'high-loa']);

		$claimMapper = $this->createMock(OidcClaimMapperService::class);
		$claimMapper->method('mapClaims')->willReturn(['identityType' => 'eherkenning', 'identityRef' => 'kvk-1', 'subjectRef' => null, 'audience' => 'supplier']);
		$claimMapper->method('mapLoaToTrust')->willReturn('high');

		$stateStore = $this->createMock(OidcStateStoreService::class);
		$stateStore->method('consume')->willReturn($this->pendingFixture());

		$accounts = $this->createMock(PortalAccountService::class);
		// subjectRefOverride MUST be null (the claim mapper said "derive") —
		// never a value that could have come from a request parameter.
		$accounts->expects($this->once())->method('findOrCreate')
			->with('eherkenning', 'kvk-1', 'gemeente-x', 'supplier', null)
			->willReturn(['subjectRef' => 'server-derived-subject', 'isNew' => true]);

		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())->method('issueSession')
			->with('server-derived-subject', 'supplier', 'gemeente-x', 'high', ['supplier:read'])
			->willReturn(['token' => 'signed.bearer.jwt', 'jti' => 'jti-1']);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $url) => 'https://portal.example' . $url);

		$response = $this->controller(
			session: $session,
			orgConfig: $orgConfig,
			oidc: $oidc,
			claimMapper: $claimMapper,
			stateStore: $stateStore,
			accounts: $accounts,
			urlGenerator: $urlGenerator
		)->oidcCallback(state: 's', code: 'c');

		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
		$redirectUrl = $response->getRedirectURL();
		$this->assertStringStartsWith('https://portal.example/portal#token=', $redirectUrl);
		$this->assertStringContainsString(rawurlencode('signed.bearer.jwt'), $redirectUrl);
		// The FRAGMENT carries the token, never the query string.
		$this->assertStringNotContainsString('?token=', $redirectUrl);

	}//end testOidcCallbackMintsASessionAndRedirectsWithTheBearerInTheFragment()

	/**
	 * @return array<string, mixed>
	 */
	public function oidcConfigFixture(): array {
		return [
			'provider' => 'eherkenning',
			'issuer' => 'https://broker.example',
			'clientId' => 'rp-client-1',
			'clientSecret' => 'super-secret-value',
			'scopes' => ['openid'],
			'identityType' => 'eherkenning',
			'identityRefClaim' => 'sub',
			'subjectRefMap' => 'derive',
			'audienceMap' => 'supplier',
			'loaClaim' => 'acr',
			'loaMap' => [],
		];

	}//end oidcConfigFixture()

	/**
	 * @return array<string, string>
	 */
	public function discoveryFixture(): array {
		return [
			'authorization_endpoint' => 'https://broker.example/authorize',
			'token_endpoint' => 'https://broker.example/token',
			'jwks_uri' => 'https://broker.example/jwks',
		];

	}//end discoveryFixture()

	/**
	 * @return array<string, string>
	 */
	public function pendingFixture(): array {
		return [
			'nonce' => 'nonce-1',
			'codeVerifier' => 'verifier-1',
			'org' => 'gemeente-x',
			'provider' => 'eherkenning',
			'returnTo' => '/portal',
		];

	}//end pendingFixture()

	/**
	 * @return array<string, mixed>
	 */
	public function mappedFixture(): array {
		return ['identityType' => 'eherkenning', 'identityRef' => 'kvk-1', 'subjectRef' => null, 'audience' => 'supplier'];
	}//end mappedFixture()

	private function controller(
		PortalSessionService $session,
		?IConfig $config = null,
		?PortalOrganisationConfigService $orgConfig = null,
		?OidcClientService $oidc = null,
		?OidcClaimMapperService $claimMapper = null,
		?OidcStateStoreService $stateStore = null,
		?PortalAccountService $accounts = null,
		?IURLGenerator $urlGenerator = null,
		string $authorization = 'Bearer some-token',
	): SessionController {
		$request = $this->createMock(IRequest::class);
		// Defaults to a bearer being PRESENT, which is what every pre-existing
		// assertion here assumes. `''` models the anonymous visitor who offers
		// no credential at all — a different case, and now a different answer.
		$request->method('getHeader')->willReturnMap([['Authorization', $authorization]]);

		return new SessionController(
			$request,
			$session,
			($config ?? $this->createMock(IConfig::class)),
			($orgConfig ?? $this->createMock(PortalOrganisationConfigService::class)),
			($oidc ?? $this->createMock(OidcClientService::class)),
			($claimMapper ?? $this->createMock(OidcClaimMapperService::class)),
			($stateStore ?? $this->createMock(OidcStateStoreService::class)),
			($accounts ?? $this->createMock(PortalAccountService::class)),
			($urlGenerator ?? $this->createMock(IURLGenerator::class))
		);

	}//end controller()

}//end class
