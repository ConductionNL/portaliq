<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCA\Portaliq\Service\Signin\OrganisationLoginConfig;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portal-white-label-runtime-config: an empty/unknown `org` slug, or an
 * unreachable OpenRegister, all resolve to the SAME safe neutral default —
 * never a 500, never another tenant's branding, never a permissive embed
 * policy. A resolved Organisation's name + per-tenant overrides (theme,
 * logo, allowedEmbedOrigins) travel through untouched; a malformed
 * `allowedEmbedOrigins` override degrades to the empty (deny) list.
 *
 * portal-oidc-broker-login additions: `resolveOidcConfig()` (the FULL,
 * secret-carrying config — server-side only) fails closed on an unknown org,
 * an unconfigured provider, and a missing required field; the client secret
 * NEVER comes from the presentation-override blob, only from its own
 * dedicated `sensitive` IAppConfig entry. `resolve()`'s `oidcProviders` (the
 * SPA-facing, secret-free list) only lists providers with a complete config.
 *
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.2
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.3
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.1
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T01
 */
class PortalOrganisationConfigServiceTest extends TestCase {

	public function testEmptySlugResolvesToNeutralDefault(): void {
		$service = $this->service();
		$config = $service->resolve('');

		$this->assertSame('Portaliq', $config['organisationName']);
		$this->assertSame('default', $config['theme']);
		$this->assertSame([], $config['allowedEmbedOrigins']);
		$this->assertSame('nl', $config['locale']);

	}//end testEmptySlugResolvesToNeutralDefault()

	public function testLocaleDefaultsToNlWhenAbsent(): void {
		$service = $this->service();
		$this->assertSame('nl', $service->resolve('')['locale']);

	}//end testLocaleDefaultsToNlWhenAbsent()

	public function testSupportedLocaleIsHonoured(): void {
		$service = $this->service();
		$this->assertSame('en', $service->resolve(orgSlug: '', locale: 'en-US')['locale']);
		$this->assertSame('nl', $service->resolve(orgSlug: '', locale: 'nl-NL')['locale']);

	}//end testSupportedLocaleIsHonoured()

	public function testUnsupportedLocaleFallsBackToNl(): void {
		$service = $this->service();
		$this->assertSame('nl', $service->resolve(orgSlug: '', locale: 'fr-FR')['locale']);
		$this->assertSame('nl', $service->resolve(orgSlug: '', locale: '')['locale']);

	}//end testUnsupportedLocaleFallsBackToNl()

	public function testUnknownSlugResolvesToNeutralDefaultNotAnError(): void {
		$mapper = new class {
			public function findBySlug(string $slug) {
				throw new RuntimeException('not found');
			}
		};

		$service = $this->service(mapper: $mapper);
		$config = $service->resolve('unknown-tenant');

		$this->assertSame('Portaliq', $config['organisationName']);
		$this->assertSame([], $config['allowedEmbedOrigins']);

	}//end testUnknownSlugResolvesToNeutralDefaultNotAnError()

	public function testOpenRegisterUnavailableResolvesToNeutralDefault(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

		$service = new PortalOrganisationConfigService(
			$container,
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
			new OidcClaimMapperService()
		);

		$config = $service->resolve('gemeente-x');
		$this->assertSame('Portaliq', $config['organisationName']);

	}//end testOpenRegisterUnavailableResolvesToNeutralDefault()

	public function testResolvedOrganisationCarriesItsNameAndOverrides(): void {
		$mapper = new class {
			public function findBySlug(string $slug) {
				return new class {
					public function getUuid() {
						return 'org-uuid-1';
					}

					public function getName() {
						return 'Gemeente X';
					}
				};
			}
		};

		$overrides = [
			'theme' => 'utrecht',
			'logo' => '/apps/portaliq/img/gemeente-x.svg',
			'allowedEmbedOrigins' => ['https://gemeente-x.example'],
			'featureFlags' => ['aiCompanion' => true],
		];

		$service = $this->service(mapper: $mapper, overridesJson: json_encode($overrides));
		$config = $service->resolve('gemeente-x');

		$this->assertSame('Gemeente X', $config['organisationName']);
		$this->assertSame('gemeente-x', $config['organisationSlug']);
		$this->assertSame('utrecht', $config['theme']);
		$this->assertSame('/apps/portaliq/img/gemeente-x.svg', $config['logo']);
		$this->assertSame(['https://gemeente-x.example'], $config['allowedEmbedOrigins']);
		$this->assertSame(['aiCompanion' => true], $config['featureFlags']);

	}//end testResolvedOrganisationCarriesItsNameAndOverrides()

	public function testMalformedAllowedEmbedOriginsDegradesToEmptyDenyList(): void {
		$mapper = new class {
			public function findBySlug(string $slug) {
				return new class {
					public function getUuid() {
						return 'org-uuid-2';
					}

					public function getName() {
						return 'Gemeente Y';
					}
				};
			}
		};

		// A malformed override (string instead of array) must fail closed to
		// an empty (deny-embed) list, never to something permissive.
		$service = $this->service(mapper: $mapper, overridesJson: json_encode(['allowedEmbedOrigins' => 'https://evil.example']));
		$config = $service->resolve('gemeente-y');

		$this->assertSame([], $config['allowedEmbedOrigins']);

	}//end testMalformedAllowedEmbedOriginsDegradesToEmptyDenyList()

	/**
	 * portal-oidc-broker-login: a configured `eherkenning` provider surfaces
	 * in `resolve()`'s secret-free `oidcProviders`, and `resolveOidcConfig()`
	 * returns the FULL merged config (issuer/clientId/scopes/claimMap/loaMap)
	 * — with the secret coming ONLY from the dedicated sensitive key, never
	 * from the presentation-override blob.
	 */
	public function testConfiguredProviderSurfacesInOidcProvidersAndResolvesFully(): void {
		$overrides = [
			'oidc' => [
				'eherkenning' => [
					'issuer' => 'https://broker.example/idp',
					'clientId' => 'rp-client-1',
					'scopes' => ['openid', 'kvk'],
				],
			],
		];

		$service = $this->oidcService(overridesJson: json_encode($overrides), secret: 's3cr3t-value-0000000000');

		$config = $service->resolve('gemeente-x');
		$this->assertSame([['provider' => 'eherkenning', 'label' => 'eHerkenning', 'route' => 'oidc']], $config['oidcProviders']);
		// The client secret is NEVER present anywhere in the SPA-facing shape.
		$this->assertStringNotContainsString('s3cr3t-value', (string)json_encode($config));

		$full = $service->resolveOidcConfig('gemeente-x', 'eherkenning');
		$this->assertNotNull($full);
		$this->assertSame('https://broker.example/idp', $full['issuer']);
		$this->assertSame('rp-client-1', $full['clientId']);
		$this->assertSame('s3cr3t-value-0000000000', $full['clientSecret']);
		$this->assertSame(['openid', 'kvk'], $full['scopes']);
		$this->assertSame('eherkenning', $full['identityType']);

	}//end testConfiguredProviderSurfacesInOidcProvidersAndResolvesFully()

	public function testUnconfiguredProviderResolvesToNullNotAnError(): void {
		$service = $this->oidcService(overridesJson: '{}', secret: '');

		$this->assertNull($service->resolveOidcConfig('gemeente-x', 'digid'));
		$this->assertSame([], $service->resolve('gemeente-x')['oidcProviders']);

	}//end testUnconfiguredProviderResolvesToNullNotAnError()

	public function testResolveOidcConfigFailsClosedWithoutAClientSecret(): void {
		$overrides = [
			'oidc' => [
				'digid' => [
					'issuer' => 'https://broker.example/idp',
					'clientId' => 'rp-client-1',
				],
			],
		];

		// No secret configured at the dedicated key — the provider must NOT
		// be treated as configured, in either resolveOidcConfig() or the
		// SPA-facing oidcProviders list.
		$service = $this->oidcService(overridesJson: json_encode($overrides), secret: '');

		$this->assertNull($service->resolveOidcConfig('gemeente-x', 'digid'));
		$this->assertSame([], $service->resolve('gemeente-x')['oidcProviders']);

	}//end testResolveOidcConfigFailsClosedWithoutAClientSecret()

	public function testResolveOidcConfigRejectsAnUnknownProviderString(): void {
		$service = $this->oidcService(overridesJson: '{}', secret: '');

		$this->assertNull($service->resolveOidcConfig('gemeente-x', 'not-a-real-provider'));

	}//end testResolveOidcConfigRejectsAnUnknownProviderString()

	/**
	 * `isLoginProviderAllowed()` ADMITS only a declared provider on a
	 * resolvable organisation.
	 *
	 * This is the positive control for the four refusal tests below. Without
	 * it they are worthless: a method that returned `false` unconditionally
	 * would satisfy every one of them, and "fails closed" would be indis-
	 * tinguishable from "never works".
	 */
	public function testIsLoginProviderAllowedAdmitsADeclaredProvider(): void {
		$service = $this->oidcService(
			overridesJson: json_encode(['oidc' => ['digid' => ['issuer' => 'https://broker.example/idp']]]),
			secret: 's3cret'
		);

		$this->assertTrue($service->isLoginProviderAllowed('gemeente-x', 'digid'));

	}//end testIsLoginProviderAllowedAdmitsADeclaredProvider()

	/**
	 * Every way this can refuse, exercised one at a time.
	 *
	 * The method has four independent guards and they fail for different
	 * reasons: no tenant named, a provider outside the closed allow-list, an
	 * organisation that does not resolve, and an organisation carrying no
	 * `oidc` block at all. A single "returns false" test would pass while
	 * three of the four were dead.
	 *
	 * The provider allow-list matters most: it is a CLOSED set, so an
	 * attacker-supplied `?provider=` string can never reach the resolver.
	 *
	 * @param string $orgSlug  The tenant slug under test.
	 * @param string $provider The provider under test.
	 * @param bool   $resolvable Whether the organisation resolves.
	 * @param string $overrides  The presentation-override JSON.
	 *
	 * @dataProvider refusedLoginProvider
	 */
	public function testIsLoginProviderAllowedRefuses(
		string $orgSlug,
		string $provider,
		bool $resolvable,
		string $overrides
	): void {
		$service = ($resolvable === true)
			? $this->oidcService(overridesJson: $overrides, secret: 's3cret')
			: $this->service(overridesJson: $overrides);

		$this->assertFalse($service->isLoginProviderAllowed($orgSlug, $provider));

	}//end testIsLoginProviderAllowedRefuses()

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
	 */
	public static function refusedLoginProvider(): array {
		$declared = '{"oidc":{"digid":{"issuer":"https://broker.example/idp"}}}';

		return [
			'no tenant named' => ['', 'digid', true, $declared],
			'provider outside the closed allow-list' => ['gemeente-x', 'not-a-real-provider', true, $declared],
			'organisation does not resolve' => ['gemeente-x', 'digid', false, $declared],
			'organisation declares no oidc block' => ['gemeente-x', 'digid', true, '{}'],
			'oidc block is not an object' => ['gemeente-x', 'digid', true, '{"oidc":"yes"}'],
			'provider absent from a populated oidc block' => ['gemeente-x', 'eidas', true, $declared],
		];

	}//end refusedLoginProvider()

	/**
	 * Builds a service against a resolvable "gemeente-x" organisation, with
	 * `getValueString` returning `$overridesJson` for the presentation-
	 * override key and `$secret` for ANY `oidc_secret_*` key.
	 */
	private function oidcService(string $overridesJson, string $secret): PortalOrganisationConfigService {
		$mapper = new class {
			public function findBySlug(string $slug) {
				return new class {
					public function getUuid() {
						return 'org-uuid-x';
					}

					public function getName() {
						return 'Gemeente X';
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($mapper);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($overridesJson, $secret) {
				if (str_starts_with($key, 'oidc_secret_') === true) {
					return $secret;
				}

				return $overridesJson;
			}
		);

		return new PortalOrganisationConfigService(
			$container,
			$appConfig,
			$this->createMock(LoggerInterface::class),
			new OidcClaimMapperService()
		);

	}//end oidcService()

	/**
	 * The message box channel exists only when the organisation names both an
	 * integriq digital post source and the label residents read
	 * (inbox-berichtenbox-channel, REQ-MBC-001). Half a configuration is no
	 * channel: a source without a label would show residents an empty choice,
	 * a label without a source would promise a send nothing can make.
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-organisation-turns-the-channel-on-req-mbc-001
	 */
	public function testMessageBoxNeedsSourceAndLabel(): void {
		$mapper = $this->oneOrganisation();

		$both = $this->service(mapper: $mapper, overridesJson: json_encode(['messageBox' => ['sourceId' => 'berichtenbox-venray', 'label' => 'MijnOverheid Berichtenbox']]));
		$this->assertSame(['sourceId' => 'berichtenbox-venray', 'label' => 'MijnOverheid Berichtenbox'], $both->messageBox('gemeente-x'));

		foreach ([
			['sourceId' => 'berichtenbox-venray'],
			['label' => 'MijnOverheid Berichtenbox'],
			['sourceId' => '', 'label' => 'MijnOverheid Berichtenbox'],
			['sourceId' => ['x'], 'label' => 'MijnOverheid Berichtenbox'],
			'on',
		] as $half) {
			$service = $this->service(mapper: $mapper, overridesJson: json_encode(['messageBox' => $half]));
			$this->assertNull($service->messageBox('gemeente-x'), 'half a configuration is no channel: '.json_encode($half));
		}

		$this->assertNull($this->service(mapper: $mapper, overridesJson: '{}')->messageBox('gemeente-x'));
		$this->assertNull($both->messageBox(''), 'no organisation, no channel');
		$this->assertNull($this->service(overridesJson: json_encode(['messageBox' => ['sourceId' => 'a', 'label' => 'b']]))->messageBox('gemeente-x'), 'an organisation that does not resolve has no channel');

	}//end testMessageBoxNeedsSourceAndLabel()

	/**
	 * A service whose config answers per key: the override blob, the broker
	 * secret and an OIDC client secret.
	 *
	 * @param array<string, mixed> $overrides    The organisation's override.
	 * @param string               $brokerSecret The broker secret.
	 * @param string               $oidcSecret   The OIDC client secret.
	 *
	 * @return PortalOrganisationConfigService
	 */
	private function routedService(array $overrides, string $brokerSecret = 'consumer-secret-1', string $oidcSecret = ''): PortalOrganisationConfigService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->oneOrganisation());

		return new PortalOrganisationConfigService(
			$container,
			$this->routedConfig(overrides: $overrides, brokerSecret: $brokerSecret, oidcSecret: $oidcSecret),
			$this->createMock(LoggerInterface::class),
			new OidcClaimMapperService()
		);
	}//end routedService()

	/**
	 * The organisation login config over the same answers.
	 *
	 * @param array<string, mixed> $overrides    The organisation's override.
	 * @param string               $brokerSecret The broker secret.
	 *
	 * @return OrganisationLoginConfig
	 */
	private function loginConfig(array $overrides, string $brokerSecret = 'consumer-secret-1'): OrganisationLoginConfig {
		return new OrganisationLoginConfig(
			$this->routedService(overrides: $overrides, brokerSecret: $brokerSecret),
			$this->routedConfig(overrides: $overrides, brokerSecret: $brokerSecret, oidcSecret: '')
		);
	}//end loginConfig()

	/**
	 * App config answering per key.
	 *
	 * @param array<string, mixed> $overrides    The override blob.
	 * @param string               $brokerSecret The broker secret.
	 * @param string               $oidcSecret   The OIDC client secret.
	 *
	 * @return IAppConfig
	 */
	private function routedConfig(array $overrides, string $brokerSecret, string $oidcSecret): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($overrides, $brokerSecret, $oidcSecret): string {
				if ($key === 'broker_secret_org-uuid-1') {
					return $brokerSecret;
				}

				if (str_starts_with($key, 'oidc_secret_') === true) {
					return $oidcSecret;
				}

				return (string)json_encode($overrides);
			}
		);

		return $appConfig;
	}//end routedConfig()

	/**
	 * A complete broker route: both addresses, the consumer id, and DigiD
	 * routed to it.
	 *
	 * @return array<string, mixed>
	 */
	private function brokerOverrides(): array {
		return [
			'loginRoutes' => ['digid' => 'broker'],
			'broker' => [
				'startUrl' => 'https://integriq.example/apps/integriq/idp/start',
				'exchangeUrl' => 'https://integriq.example/apps/integriq/api/idp/envelope/exchange',
				'consumerId' => 'portaliq-venray',
			],
		];
	}//end brokerOverrides()

	/**
	 * T01: the broker route counts only with every field and the secret; a
	 * missing or unusable one leaves it unconfigured.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function testBrokerRouteNeedsEveryField(): void {
		$config = $this->loginConfig(overrides: $this->brokerOverrides())->resolveBrokerConfig('gemeente-x', 'digid');
		$this->assertSame('https://integriq.example/apps/integriq/idp/start', $config['startUrl']);
		$this->assertSame('portaliq-venray', $config['consumerId']);
		$this->assertSame('consumer-secret-1', $config['secret']);
		$this->assertSame('org-uuid-1', $config['organisationUuid']);
		$this->assertSame('client', $config['audience']);

		foreach (['startUrl', 'exchangeUrl', 'consumerId'] as $field) {
			$overrides = $this->brokerOverrides();
			unset($overrides['broker'][$field]);
			$this->assertNull($this->loginConfig(overrides: $overrides)->resolveBrokerConfig('gemeente-x', 'digid'), 'missing '.$field);
		}

		$overrides = $this->brokerOverrides();
		$overrides['broker']['startUrl'] = 'javascript:alert(1)';
		$this->assertNull($this->loginConfig(overrides: $overrides)->resolveBrokerConfig('gemeente-x', 'digid'), 'not an http(s) address');
		$this->assertNull($this->loginConfig(overrides: $this->brokerOverrides(), brokerSecret: '')->resolveBrokerConfig('gemeente-x', 'digid'), 'no secret');
		$this->assertNull($this->loginConfig(overrides: $this->brokerOverrides())->resolveBrokerConfig('gemeente-x', 'eherkenning'), 'eHerkenning is not routed to the broker');

		$generic = $this->brokerOverrides();
		$generic['loginRoutes'] = ['generic' => 'broker'];
		$this->assertSame('oidc', $this->loginConfig(overrides: $generic)->loginRouteFor('gemeente-x', 'generic'), 'integriq brokers no generic login');
	}//end testBrokerRouteNeedsEveryField()

	/**
	 * T01: the broker secret never reaches the SPA's config, and it is written
	 * to its own sensitive entry.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function testBrokerSecretIsNeverInResolve(): void {
		$resolved = $this->routedService(overrides: $this->brokerOverrides())->resolve('gemeente-x');
		$this->assertStringNotContainsString('consumer-secret-1', (string)json_encode($resolved));
		$this->assertStringNotContainsString('exchange', (string)json_encode($resolved['oidcProviders']));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects($this->once())->method('setValueString')
			->with('portaliq', 'broker_secret_org-uuid-1', 's3cret', false, true)
			->willReturn(true);
		$service = new OrganisationLoginConfig(
			$this->createMock(PortalOrganisationConfigService::class),
			$appConfig
		);
		$this->assertTrue($service->setBrokerSecret('org-uuid-1', 's3cret'));
		$this->assertFalse($service->setBrokerSecret('', 's3cret'));
	}//end testBrokerSecretIsNeverInResolve()

	/**
	 * T02: every listed provider says which route it takes; one with no
	 * `loginRoutes` entry keeps the OIDC route.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function testProviderListCarriesRoute(): void {
		$overrides = $this->brokerOverrides() + ['oidc' => ['eherkenning' => ['issuer' => 'https://idp.example', 'clientId' => 'c1']]];
		$providers = $this->routedService(overrides: $overrides, oidcSecret: 'oidc-secret')->resolve('gemeente-x')['oidcProviders'];

		$this->assertSame(
			[
				['provider' => 'digid', 'label' => 'DigiD', 'route' => 'broker'],
				['provider' => 'eherkenning', 'label' => 'eHerkenning', 'route' => 'oidc'],
			],
			$providers
		);
	}//end testProviderListCarriesRoute()

	/**
	 * T02: a provider routed to an incomplete broker shows no button, even
	 * when a complete OIDC config for it exists: the route is the choice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	public function testUnconfiguredBrokerRouteHidesTheProvider(): void {
		$overrides = $this->brokerOverrides() + ['oidc' => ['digid' => ['issuer' => 'https://idp.example', 'clientId' => 'c1']]];
		unset($overrides['broker']['exchangeUrl']);

		$this->assertSame([], $this->routedService(overrides: $overrides, oidcSecret: 'oidc-secret')->resolve('gemeente-x')['oidcProviders']);
	}//end testUnconfiguredBrokerRouteHidesTheProvider()

	/**
	 * An organisation mapper that resolves every slug to one organisation.
	 *
	 * @return object
	 */
	private function oneOrganisation(): object {
		return new class {
			public function findBySlug(string $slug) {
				return new class {
					public function getUuid() {
						return 'org-uuid-1';
					}

					public function getName() {
						return 'Gemeente X';
					}
				};
			}
		};

	}//end oneOrganisation()

	private function service(?object $mapper = null, ?string $overridesJson = null): PortalOrganisationConfigService {
		$container = $this->createMock(ContainerInterface::class);
		if ($mapper !== null) {
			$container->method('get')->willReturn($mapper);
		} else {
			$container->method('get')->willThrowException(new RuntimeException('unavailable'));
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($overridesJson ?? '{}');

		return new PortalOrganisationConfigService(
			$container,
			$appConfig,
			$this->createMock(LoggerInterface::class),
			new OidcClaimMapperService()
		);

	}//end service()

}//end class
