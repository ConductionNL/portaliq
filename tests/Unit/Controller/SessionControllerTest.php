<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SessionController;
use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\OidcClientService;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Signin\OrganisationLoginConfig;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * portal-controller-http-test-coverage: the three HTTP-facing behaviours
 * `supplier-portal` T02 describes as DONE but never turned into a regression
 * test — `GET /portal/api/session` resolve (both branches), the dev-login
 * gate (open vs 404-closed, the production posture the React portal's shell
 * described), and logout's real revocation
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

	/**
	 * The SECOND guard, reached only once the policy has already said yes.
	 *
	 * `oidcStart()` asks two questions in sequence: may this org+provider
	 * start a login, and can the secret-bearing config actually be resolved.
	 * The test above is named for the second and measures the first — it
	 * leaves `isLoginProviderAllowed` at the mock's default `false`, so it
	 * returns at the policy guard and never executes the config guard at all.
	 *
	 * That arm is not redundant. The policy reads the presentation-override
	 * blob; the resolver additionally requires the client secret from its own
	 * dedicated `sensitive` entry. An organisation that declares a provider
	 * but whose secret is missing or has been rotated away lands exactly here,
	 * and it must produce the SAME generic error — a distinguishable response
	 * would tell an anonymous caller which tenants have half-configured
	 * identity providers.
	 */
	public function testOidcStartFailsClosedWhenThePolicyAllowsButTheConfigWillNotResolve(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
		$orgConfig->method('resolveOidcConfig')->willReturn(null);

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->expects($this->never())->method('discover');

		$response = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc)
			->oidcStart(org: 'gemeente-x', provider: 'eherkenning');

		// Byte-identical to the policy refusal above, deliberately.
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('oidc_failed', $response->getData()['error']);

	}//end testOidcStartFailsClosedWhenThePolicyAllowsButTheConfigWillNotResolve()

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
	/**
	 * signin-integriq-broker-login D2: a provider the organisation routes to
	 * integriq's broker is forwarded to the broker start, with no OIDC secret
	 * read; the public site's sign-in links reach it this way.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-broker-start-binds-the-login-to-one-organisation-and-one-provider-req-bel-002
	 */
	public function testOidcStartForwardsABrokerRoutedProvider(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->expects($this->never())->method('resolveOidcConfig');
		$loginConfig = $this->createMock(OrganisationLoginConfig::class);
		$loginConfig->method('loginRouteFor')->willReturnMap([['gemeente-x', 'digid', 'broker']]);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $parameters = []): string => '/'.$route.'?'.http_build_query($parameters)
		);
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'venray', 'organisation' => 'gemeente-x']);

		$response = $this->controller(
			session: $this->createMock(PortalSessionService::class),
			orgConfig: $orgConfig,
			urlGenerator: $urls,
			portals: $portals,
			loginConfig: $loginConfig
		)->oidcStart(provider: 'digid', portal: 'venray');

		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
		// The resolved portal rides along, so the broker login returns to it
		// (portal-broker-login-keeps-the-portal).
		$this->assertSame('/portaliq.brokerSession.start?org=gemeente-x&provider=digid&portal=venray', $response->getRedirectURL());
	}//end testOidcStartForwardsABrokerRoutedProvider()

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
	 * #802: the public site starts a sign-in with `?provider=&portal=<slug>`,
	 * never `?org=`. Dispatched the way Nextcloud's dispatcher does it (each
	 * method parameter filled from the request by name, anything else
	 * dropped), that link used to reach `oidcStart()` with an empty
	 * organisation and answer the generic error for every configured
	 * provider. The portal's own `organisation` field names the tenant.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-signin-integriq-broker-login/design.md#d2-two-new-routes-and-the-spas-follow-the-route-field
	 */
	public function testOidcStartFromTheSiteResolvesTheOrganisationFromThePortalSlug(): void {
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->expects($this->once())->method('resolve')
			->with($this->anything(), 'la-franken')
			->willReturn(['slug' => 'la-franken', 'organisation' => 'gemeente-x']);

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->expects($this->once())->method('isLoginProviderAllowed')->with('gemeente-x', 'eherkenning')->willReturn(true);
		$orgConfig->method('resolveOidcConfig')->with('gemeente-x', 'eherkenning')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn($this->discoveryFixture());
		$oidc->method('generateToken')->willReturnOnConsecutiveCalls('state-1', 'nonce-1');
		$oidc->method('generatePkce')->willReturn(['verifier' => 'verifier-1', 'challenge' => 'challenge-1']);
		$oidc->method('buildAuthorizationUrl')->willReturn('https://broker.example/authorize?state=state-1');

		// The state row must carry the ORGANISATION, not the portal slug: the
		// callback resolves the broker config from what was stored here.
		$stateStore = $this->createMock(OidcStateStoreService::class);
		$stateStore->expects($this->once())->method('create')->with('state-1', 'nonce-1', 'verifier-1', 'gemeente-x', 'eherkenning', $this->anything())->willReturn(true);

		$controller = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc, stateStore: $stateStore, portals: $portals);
		$response = $this->dispatch(controller: $controller, method: 'oidcStart', params: ['provider' => 'eherkenning', 'portal' => 'la-franken']);

		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());

	}//end testOidcStartFromTheSiteResolvesTheOrganisationFromThePortalSlug()


	/**
	 * An explicit `org` still wins: the portal SPA sends it, and a slug the
	 * caller did not name must not be looked up on its behalf.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-29-signin-integriq-broker-login/design.md#d2-two-new-routes-and-the-spas-follow-the-route-field
	 */
	public function testOidcStartWithAnExplicitOrgDoesNotConsultThePortal(): void {
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->expects($this->never())->method('resolve');

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->expects($this->once())->method('isLoginProviderAllowed')->with('gemeente-x', 'digid')->willReturn(false);

		$controller = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, portals: $portals);
		$response = $this->dispatch(controller: $controller, method: 'oidcStart', params: ['org' => 'gemeente-x', 'provider' => 'digid']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());

	}//end testOidcStartWithAnExplicitOrgDoesNotConsultThePortal()


	/**
	 * A portal slug that names no published portal, or a portal with no
	 * organisation, is the same generic error as every other refusal here:
	 * the start must not become an oracle for which portals exist.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
	 */
	public function testOidcStartWithAnUnknownPortalIsTheGenericError(): void {
		foreach ([null, ['slug' => 'la-franken']] as $resolved) {
			$portals = $this->createMock(originalClassName: PortalResolver::class);
			$portals->method('resolve')->willReturn($resolved);

			$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
			$orgConfig->expects($this->never())->method('resolveOidcConfig');

			$controller = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, portals: $portals);
			$response = $this->dispatch(controller: $controller, method: 'oidcStart', params: ['provider' => 'digid', 'portal' => 'la-franken']);

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'oidc_failed'], $response->getData());
		}

	}//end testOidcStartWithAnUnknownPortalIsTheGenericError()


	/**
	 * Call a controller method the way Nextcloud's dispatcher does: every
	 * declared parameter is taken from the request by NAME and falls back to
	 * its default, and a request parameter the method does not declare is
	 * silently dropped. Calling with PHP named arguments instead would turn
	 * an undeclared parameter into an Error the live request never sees.
	 *
	 * @param SessionController    $controller The controller.
	 * @param string               $method     The action.
	 * @param array<string, mixed> $params     The request parameters.
	 *
	 * @return \OCP\AppFramework\Http\Response The response.
	 */
	private function dispatch(SessionController $controller, string $method, array $params): \OCP\AppFramework\Http\Response {
		$reflection = new \ReflectionMethod($controller, $method);
		$arguments = [];
		foreach ($reflection->getParameters() as $parameter) {
			$name = $parameter->getName();
			$arguments[] = array_key_exists($name, $params) === true ? $params[$name] : $parameter->getDefaultValue();
		}

		return $reflection->invokeArgs($controller, $arguments);

	}//end dispatch()

	/**
	 * @return array<string, array{0: callable}>
	 */
	public static function oidcCallbackFailureProvider(): array {
		return [
			'missing state' => [static fn (SessionControllerTest $t) => ['args' => ['state' => '', 'code' => 'c']]],
			'missing code' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => '']]],
			'broker reported error' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c', 'error' => 'access_denied']]],
			'unknown/reused state' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => null]],
			'a state written for the integriq broker route (signin-integriq-broker-login D3)' => [static fn (SessionControllerTest $t) => ['args' => ['state' => 's', 'code' => 'c'], 'stateConsume' => ['route' => 'broker', 'codeVerifier' => ''] + $t->pendingFixture(), 'orgConfig' => $t->oidcConfigFixture(), 'discover' => $t->discoveryFixture(), 'exchangeCode' => ['id_token' => 'x.y.z'], 'verifyIdToken' => ['sub' => 'abc'], 'mapClaims' => $t->mappedFixture(), 'findOrCreate' => ['subjectRef' => 'sub-1', 'isNew' => true]]],
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
	/**
	 * signin-eherkenning-branch REQ-SEB-001, through the REAL claim mapper: an
	 * organisation that maps the broker's vestigingsnummer claim gives a
	 * session restricted to that branch.
	 */
	public function testOidcCallbackCarriesTheLoginBranchIntoTheSession(): void {
		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
		$mapper = new OidcClaimMapperService();
		$orgConfig->method('resolveOidcConfig')->willReturn(
			$mapper->applyPreset('eherkenning', ['issuer' => 'https://broker.example', 'clientId' => 'rp', 'clientSecret' => 'x', 'claimMap' => ['branch' => 'vestigingsnummer']])
		);

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn($this->discoveryFixture());
		$oidc->method('exchangeCode')->willReturn(['id_token' => 'x.y.z']);
		$oidc->method('verifyIdToken')->willReturn(['sub' => 'kvk-1', 'acr' => 'high-loa', 'vestigingsnummer' => '000012345678']);

		$stateStore = $this->createMock(OidcStateStoreService::class);
		$stateStore->method('consume')->willReturn($this->pendingFixture());

		$accounts = $this->createMock(PortalAccountService::class);
		$accounts->method('findOrCreate')->willReturn(['subjectRef' => 'server-derived-subject', 'isNew' => false]);

		$session = $this->createMock(PortalSessionService::class);
		$session->expects($this->once())->method('issueSession')
			->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), '000012345678')
			->willReturn(['token' => 'signed.bearer.jwt', 'jti' => 'jti-1']);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $url) => 'https://portal.example' . $url);

		$response = $this->controller(
			session: $session,
			orgConfig: $orgConfig,
			oidc: $oidc,
			claimMapper: $mapper,
			stateStore: $stateStore,
			accounts: $accounts,
			urlGenerator: $urlGenerator
		)->oidcCallback(state: 's', code: 'c');

		$this->assertSame(Http::STATUS_FOUND, $response->getStatus());

	}//end testOidcCallbackCarriesTheLoginBranchIntoTheSession()

	public function testIndexReportsTheBranchInEffect(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT + ['branch' => '000012345678', 'branchRestricted' => true]);

		$data = $this->controller(session: $session)->index()->getData();

		$this->assertSame('000012345678', $data['branch']);
		$this->assertTrue($data['branchRestricted']);

	}//end testIndexReportsTheBranchInEffect()

	/**
	 * identity-profile-page T06 (REQ-IPP-005): the session says when to ask
	 * for an e-mail address: none in use, or dispatch flagged the account.
	 *
	 * @return void
	 */
	public function testIndexAsksForAnEmailAddressWhenNoneIsInUse(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);
		$cases = [
			'a confirmed address' => [['email' => 'a@example.nl'], false],
			'no address' => [['email' => ''], true],
			'dispatch flagged it' => [['email' => 'a@example.nl', 'needsAlternativeContact' => true], true],
		];
		foreach ($cases as $label => [$account, $expected]) {
			$accounts = $this->createMock(PortalAccountService::class);
			$accounts->method('findBySubjectRef')->with('s1')->willReturn($account + ['subjectRef' => 's1']);

			$data = $this->controller(session: $session, accounts: $accounts)->index()->getData();

			$this->assertSame($expected, $data['contactPrompt'], $label);
		}

	}//end testIndexAsksForAnEmailAddressWhenNoneIsInUse()

	/**
	 * site-header-names-the-person: the session carries the account's display
	 * name, and never a value that is only the subject reference.
	 *
	 * @return void
	 */
	public function testIndexNamesThePersonNeverTheReference(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);
		$cases = [
			'a display name' => [['displayName' => ' Fatima Hulstkamp '], 'Fatima Hulstkamp'],
			'no display name' => [['displayName' => ''], ''],
			'the reference as a name' => [['displayName' => 's1'], ''],
			'the BSN as a name' => [['displayName' => '999993653', 'identityRef' => '999993653'], ''],
			'only digits as a name' => [['displayName' => ' 123456782 '], ''],
			'no account' => [null, ''],
		];
		foreach ($cases as $label => [$account, $expected]) {
			$accounts = $this->createMock(PortalAccountService::class);
			$accounts->method('findBySubjectRef')->with('s1')->willReturn($account === null ? null : $account + ['subjectRef' => 's1']);

			$data = $this->controller(session: $session, accounts: $accounts)->index()->getData();

			$this->assertSame($expected, $data['displayName'], $label);
		}

	}//end testIndexNamesThePersonNeverTheReference()

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
	 * REQ-SIS-001: the session answer says when it ends.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 */
	public function testIndexReportsExpiry(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);
		$session->method('sessionTimes')->willReturn(['expiresAt' => 1900, 'hardExpiresAt' => 29800, 'idleTimeout' => 900]);

		$data = $this->controller(session: $session)->index()->getData();

		$this->assertSame(1900, $data['expiresAt']);
		$this->assertSame(29800, $data['hardExpiresAt']);
		$this->assertSame(900, $data['idleTimeout']);

	}//end testIndexReportsExpiry()

	/**
	 * REQ-SIS-001: the refresh answer says when the rotated session ends.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T02
	 */
	public function testRefreshReportsExpiry(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('refreshSession')->willReturn(['token' => 'new.signed.jwt', 'jti' => 'jti-2', 'expiresAt' => 2800, 'hardExpiresAt' => 29800, 'idleTimeout' => 900]);

		$data = $this->controller(session: $session)->refresh()->getData();

		$this->assertSame('new.signed.jwt', $data['token']);
		$this->assertSame(2800, $data['expiresAt']);
		$this->assertSame(29800, $data['hardExpiresAt']);
		$this->assertSame(900, $data['idleTimeout']);
		$this->assertArrayNotHasKey('jti', $data);

	}//end testRefreshReportsExpiry()

	/**
	 * REQ-SIS-005: `silent=1` records the flag on the state row and asks the
	 * broker for no prompt; without it neither happens.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T07
	 */
	public function testSilentStartRecordsTheFlag(): void {
		foreach (['1' => [true, 'none'], '' => [false, '']] as $silent => [$flag, $prompt]) {
			$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
			$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
			$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

			$oidc = $this->createMock(OidcClientService::class);
			$oidc->method('discover')->willReturn($this->discoveryFixture());
			$oidc->method('generateToken')->willReturnOnConsecutiveCalls('state-1', 'nonce-1');
			$oidc->method('generatePkce')->willReturn(['verifier' => 'verifier-1', 'challenge' => 'challenge-1']);
			$oidc->expects($this->once())->method('buildAuthorizationUrl')
				->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $prompt)
				->willReturn('https://broker.example/authorize?state=state-1');

			$stateStore = $this->createMock(OidcStateStoreService::class);
			$stateStore->expects($this->once())->method('create')
				->with('state-1', 'nonce-1', 'verifier-1', 'gemeente-x', 'digid', $this->anything(), $flag)
				->willReturn(true);

			$response = $this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc, stateStore: $stateStore)
				->oidcStart(org: 'gemeente-x', provider: 'digid', silent: (string)$silent);

			$this->assertSame(Http::STATUS_FOUND, $response->getStatus());
		}

	}//end testSilentStartRecordsTheFlag()

	/**
	 * A login started from a portal returns to that portal, so its title and
	 * branding survive the sign-in; an unknown portal returns to the plain
	 * portal address (portal-signin-on-its-own-address T3).
	 *
	 * @spec openspec/changes/portal-signin-on-its-own-address/tasks.md#T3
	 */
	public function testALoginStartedFromAPortalReturnsToIt(): void {
		// The site, never the retired React portal (site-reaches-portal-parity REQ-SRP-049).
		foreach (['wilgenboom' => '/apps/portaliq/site?portal=wilgenboom', 'no-such-portal' => '/apps/portaliq/site'] as $slug => $returnTo) {
			$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
			$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
			$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

			$oidc = $this->createMock(OidcClientService::class);
			$oidc->method('discover')->willReturn($this->discoveryFixture());
			$oidc->method('generateToken')->willReturnOnConsecutiveCalls('state-1', 'nonce-1');
			$oidc->method('generatePkce')->willReturn(['verifier' => 'verifier-1', 'challenge' => 'challenge-1']);
			$oidc->method('buildAuthorizationUrl')->willReturn('https://broker.example/authorize?state=state-1');

			$urlGenerator = $this->createMock(IURLGenerator::class);
			$urlGenerator->method('linkToRoute')->willReturnCallback(
				static fn (string $name): string => $name === 'portaliq.portalPage.site' ? '/apps/portaliq/site' : '/apps/portaliq/portal'
			);

			$portals = $this->createMock(PortalResolver::class);
			$portals->method('resolve')->willReturnCallback(
				static fn ($request, string $portalSlug = ''): ?array => $portalSlug === 'wilgenboom' ? ['slug' => 'wilgenboom', 'organisation' => 'school-org'] : null
			);

			$stateStore = $this->createMock(OidcStateStoreService::class);
			$stateStore->expects($this->once())->method('create')
				->with('state-1', 'nonce-1', 'verifier-1', 'school-org', 'digid', $returnTo, false)
				->willReturn(true);

			$this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc, stateStore: $stateStore, urlGenerator: $urlGenerator, portals: $portals)
				->oidcStart(org: 'school-org', provider: 'digid', portal: $slug);
		}

	}//end testALoginStartedFromAPortalReturnsToIt()

	/**
	 * A login started on the public site returns to the page it came from;
	 * an address outside the site route falls back to the portal's site address.
	 *
	 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 */
	public function testALoginStartedOnTheSiteReturnsToThatPage(): void {
		$cases = [
			'/apps/portaliq/site?portal=wilgenboom&route=/mijn' => '/apps/portaliq/site?portal=wilgenboom&route=/mijn',
			'https://evil.example/apps/portaliq/site' => '/apps/portaliq/site?portal=wilgenboom',
		];
		foreach ($cases as $returnTo => $stored) {
			$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
			$orgConfig->method('isLoginProviderAllowed')->willReturn(true);
			$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

			$oidc = $this->createMock(OidcClientService::class);
			$oidc->method('discover')->willReturn($this->discoveryFixture());
			$oidc->method('generateToken')->willReturnOnConsecutiveCalls('state-1', 'nonce-1');
			$oidc->method('generatePkce')->willReturn(['verifier' => 'verifier-1', 'challenge' => 'challenge-1']);
			$oidc->method('buildAuthorizationUrl')->willReturn('https://broker.example/authorize?state=state-1');

			$urlGenerator = $this->createMock(IURLGenerator::class);
			$urlGenerator->method('linkToRoute')->willReturnCallback(
				static fn (string $name): string => $name === 'portaliq.portalPage.site' ? '/apps/portaliq/site' : '/apps/portaliq/portal'
			);

			$portals = $this->createMock(PortalResolver::class);
			$portals->method('resolve')->willReturn(['slug' => 'wilgenboom', 'organisation' => 'school-org']);

			$stateStore = $this->createMock(OidcStateStoreService::class);
			$stateStore->expects($this->once())->method('create')
				->with('state-1', 'nonce-1', 'verifier-1', 'school-org', 'digid', $stored, false)
				->willReturn(true);

			$this->controller(session: $this->createMock(PortalSessionService::class), orgConfig: $orgConfig, oidc: $oidc, stateStore: $stateStore, urlGenerator: $urlGenerator, portals: $portals)
				->oidcStart(provider: 'digid', portal: 'wilgenboom', returnTo: $returnTo);
		}

	}//end testALoginStartedOnTheSiteReturnsToThatPage()

	/**
	 * REQ-SIS-005: a silent attempt the broker answers with "the resident must
	 * interact" lands on the portal's login screen, with no error and no token.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T08
	 */
	public function testSilentLoginRequiredLandsQuietly(): void {
		foreach (['login_required', 'interaction_required', 'consent_required', 'account_selection_required'] as $error) {
			$stateStore = $this->createMock(OidcStateStoreService::class);
			$stateStore->expects($this->once())->method('consume')->with('s')->willReturn(['silent' => true] + $this->pendingFixture());

			$urlGenerator = $this->createMock(IURLGenerator::class);
			$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $url) => 'https://portal.example' . $url);

			$session = $this->createMock(PortalSessionService::class);
			$session->expects($this->never())->method('issueSession');

			$response = $this->controller(session: $session, stateStore: $stateStore, urlGenerator: $urlGenerator)
				->oidcCallback(state: 's', code: '', error: $error);

			$this->assertSame(Http::STATUS_FOUND, $response->getStatus(), $error);
			$this->assertSame('https://portal.example/portal', $response->getRedirectURL(), $error);
		}

	}//end testSilentLoginRequiredLandsQuietly()

	/**
	 * REQ-SIS-005: every other broker error, and any error on a row that was
	 * not silent, keeps the one generic failure.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T08
	 */
	public function testNonSilentErrorKeepsTheGenericFailure(): void {
		$cases = [
			['login_required', $this->pendingFixture()],
			['access_denied', ['silent' => true] + $this->pendingFixture()],
			['login_required', null],
		];
		foreach ($cases as [$error, $row]) {
			$stateStore = $this->createMock(OidcStateStoreService::class);
			$stateStore->method('consume')->willReturn($row);

			$response = $this->controller(session: $this->createMock(PortalSessionService::class), stateStore: $stateStore)
				->oidcCallback(state: 's', code: '', error: $error);

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $error);
			$this->assertSame(['error' => 'oidc_failed'], $response->getData());
		}

	}//end testNonSilentErrorKeepsTheGenericFailure()

	/**
	 * REQ-SIS-006: signing out of an OIDC-minted session also answers the
	 * broker's logout address, with client_id and post_logout_redirect_uri.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 */
	public function testLogoutReturnsTheBrokerLogoutUrl(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['organisation' => 'gemeente-x', 'provider' => 'digid'] + self::SUBJECT);
		$session->expects($this->once())->method('revoke')->with('jti-1');

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->expects($this->once())->method('resolveOidcConfig')->with('gemeente-x', 'digid')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn(['end_session_endpoint' => 'https://broker.example/logout'] + $this->discoveryFixture());

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $name): string => $name === 'portaliq.portalPage.site' ? '/apps/portaliq/site' : '/apps/portaliq/portal'
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $url) => 'https://portal.example' . $url);

		$data = $this->controller(session: $session, orgConfig: $orgConfig, oidc: $oidc, urlGenerator: $urlGenerator)->logout()->getData();

		$this->assertTrue($data['ok']);
		$this->assertSame(
			'https://broker.example/logout?client_id=rp-client-1&post_logout_redirect_uri=' . rawurlencode('https://portal.example/apps/portaliq/site'),
			$data['logoutUrl']
		);

	}//end testLogoutReturnsTheBrokerLogoutUrl()

	/**
	 * REQ-SIS-006: a broker that announces no end_session_endpoint gives no logoutUrl.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 */
	public function testLogoutWithoutEndSessionReturnsNone(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['organisation' => 'gemeente-x', 'provider' => 'digid'] + self::SUBJECT);

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->method('resolveOidcConfig')->willReturn($this->oidcConfigFixture());

		$oidc = $this->createMock(OidcClientService::class);
		$oidc->method('discover')->willReturn(['end_session_endpoint' => ''] + $this->discoveryFixture());

		$data = $this->controller(session: $session, orgConfig: $orgConfig, oidc: $oidc)->logout()->getData();

		$this->assertSame(['ok' => true], $data);

	}//end testLogoutWithoutEndSessionReturnsNone()

	/**
	 * REQ-SIS-006: a session without a provider (dev-login, the Nextcloud
	 * mode, integriq's broker route) never looks up a broker on sign-out.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T10
	 */
	public function testDevLoginSessionHasNoProvider(): void {
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['provider' => ''] + self::SUBJECT);

		$orgConfig = $this->createMock(PortalOrganisationConfigService::class);
		$orgConfig->expects($this->never())->method('resolveOidcConfig');

		$data = $this->controller(session: $session, orgConfig: $orgConfig)->logout()->getData();

		$this->assertSame(['ok' => true], $data);

	}//end testDevLoginSessionHasNoProvider()

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
			'route' => 'oidc',
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
		?IUserSession $userSession = null,
		?PortalResolver $portals = null,
		?OrganisationLoginConfig $loginConfig = null,
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
			($urlGenerator ?? $this->createMock(originalClassName: IURLGenerator::class)),
			($userSession ?? $this->createMock(originalClassName: IUserSession::class)),
			($portals ?? $this->createMock(originalClassName: PortalResolver::class)),
			$loginConfig
		);

	}//end controller()

	/**
	 * A visitor with no Nextcloud session is handed to Nextcloud's own login
	 * form, and no portal session is minted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function testNextcloudSignInSendsAnAnonymousVisitorToTheLoginForm(): void {
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/login?redirect_url=x');

		$response = $this->controller(session: $session, urlGenerator: $urlGenerator)->nextcloud(portal: 'demo');

		$this->assertInstanceOf(expected: RedirectResponse::class, actual: $response);
		$this->assertSame(expected: '/login?redirect_url=x', actual: $response->getRedirectURL());

	}//end testNextcloudSignInSendsAnAnonymousVisitorToTheLoginForm()

	/**
	 * A portal that does not declare the `nextcloud` mode cannot be entered
	 * through it, even by a signed-in Nextcloud user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function testNextcloudSignInRefusesAPortalThatDoesNotOfferTheMode(): void {
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'demo', 'authentication' => ['modes' => ['public', 'digid']]]);

		$response = $this->controller(
			session: $session,
			userSession: $this->signedIn(uid: 'alice'),
			portals: $portals
		)->nextcloud(portal: 'demo');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'mode_not_offered'], actual: $response->getData());

	}//end testNextcloudSignInRefusesAPortalThatDoesNotOfferTheMode()

	/**
	 * A Nextcloud user without an active portalAccount is refused rather than
	 * given an account on the spot.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function testNextcloudSignInRefusesAUserWithoutAPortalAccount(): void {
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->never())->method('issueSession');
		$accounts = $this->createMock(originalClassName: PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturn(null);
		$accounts->expects($this->never())->method('findOrCreate');

		$response = $this->controller(
			session: $session,
			accounts: $accounts,
			userSession: $this->signedIn(uid: 'alice'),
			portals: $this->portalOffering(mode: 'nextcloud')
		)->nextcloud(portal: 'demo');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());

	}//end testNextcloudSignInRefusesAUserWithoutAPortalAccount()

	/**
	 * A signed-in user with an active account gets a low-trust portal session,
	 * handed back in the URL fragment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function testNextcloudSignInHandsTheTokenBackInTheFragment(): void {
		$session = $this->createMock(originalClassName: PortalSessionService::class);
		$session->expects($this->once())
			->method('issueSession')
			->with('alice', 'client', 'org-1', 'low', ['client:read'])
			->willReturn(['token' => 'tok en']);
		$accounts = $this->createMock(originalClassName: PortalAccountService::class);
		$accounts->method('findBySubjectRef')->willReturn(
			['subjectRef' => 'alice', 'audience' => 'client', 'organisation' => 'org-1', 'status' => 'active']
		);
		$urlGenerator = $this->createMock(originalClassName: IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://nc.example' . $url);

		$response = $this->controller(
			session: $session,
			accounts: $accounts,
			urlGenerator: $urlGenerator,
			userSession: $this->signedIn(uid: 'alice'),
			portals: $this->portalOffering(mode: 'nextcloud')
		)->nextcloud(portal: 'demo', returnTo: '/apps/portaliq/site?portal=demo');

		$this->assertInstanceOf(expected: RedirectResponse::class, actual: $response);
		$this->assertSame(
			expected: 'https://nc.example/apps/portaliq/site?portal=demo#token=tok%20en',
			actual: $response->getRedirectURL()
		);

	}//end testNextcloudSignInHandsTheTokenBackInTheFragment()

	/**
	 * The Nextcloud sign-in route requires a Nextcloud session, not anonymity.
	 *
	 * The caller's Nextcloud session is the credential being exchanged for a
	 * portal one. A public route would mint a portal session for a caller who
	 * proved nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function testTheNextcloudSignInRouteExchangesASessionRatherThanCreatingOne(): void {
		$method = new \ReflectionMethod(objectOrMethod: SessionController::class, method: 'nextcloud');

		$this->assertEmpty(
			actual: $method->getAttributes(name: \OCP\AppFramework\Http\Attribute\PublicPage::class),
			message: 'a public sign-in route would mint a portal session for a caller who proved nothing'
		);
		$this->assertNotEmpty(
			actual: $method->getAttributes(name: \OCP\AppFramework\Http\Attribute\NoAdminRequired::class),
			message: 'signing in must not require being an instance administrator'
		);

	}//end testTheNextcloudSignInRouteExchangesASessionRatherThanCreatingOne()

	/**
	 * A user session signed in as the given uid.
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return IUserSession
	 */
	private function signedIn(string $uid): IUserSession {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);

		return $userSession;

	}//end signedIn()

	/**
	 * A resolver answering a portal that offers exactly one sign-in mode.
	 *
	 * @param string $mode The mode the portal declares.
	 *
	 * @return PortalResolver
	 */
	private function portalOffering(string $mode): PortalResolver {
		$portals = $this->createMock(originalClassName: PortalResolver::class);
		$portals->method('resolve')->willReturn(['slug' => 'demo', 'authentication' => ['modes' => [$mode]]]);

		return $portals;

	}//end portalOffering()

}//end class
