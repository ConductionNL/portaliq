<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\OidcClaimMapperService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
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
class PortalOrganisationConfigServiceTest extends TestCase
{

    public function testEmptySlugResolvesToNeutralDefault(): void
    {
        $service = $this->service();
        $config  = $service->resolve('');

        $this->assertSame('Portaliq', $config['organisationName']);
        $this->assertSame('default', $config['theme']);
        $this->assertSame([], $config['allowedEmbedOrigins']);
        $this->assertSame('nl', $config['locale']);

    }//end testEmptySlugResolvesToNeutralDefault()

    public function testLocaleDefaultsToNlWhenAbsent(): void
    {
        $service = $this->service();
        $this->assertSame('nl', $service->resolve('')['locale']);

    }//end testLocaleDefaultsToNlWhenAbsent()

    public function testSupportedLocaleIsHonoured(): void
    {
        $service = $this->service();
        $this->assertSame('en', $service->resolve(orgSlug: '', locale: 'en-US')['locale']);
        $this->assertSame('nl', $service->resolve(orgSlug: '', locale: 'nl-NL')['locale']);

    }//end testSupportedLocaleIsHonoured()

    public function testUnsupportedLocaleFallsBackToNl(): void
    {
        $service = $this->service();
        $this->assertSame('nl', $service->resolve(orgSlug: '', locale: 'fr-FR')['locale']);
        $this->assertSame('nl', $service->resolve(orgSlug: '', locale: '')['locale']);

    }//end testUnsupportedLocaleFallsBackToNl()

    public function testUnknownSlugResolvesToNeutralDefaultNotAnError(): void
    {
        $mapper = new class {
            public function findBySlug(string $slug)
            {
                throw new RuntimeException('not found');
            }
        };

        $service = $this->service(mapper: $mapper);
        $config  = $service->resolve('unknown-tenant');

        $this->assertSame('Portaliq', $config['organisationName']);
        $this->assertSame([], $config['allowedEmbedOrigins']);

    }//end testUnknownSlugResolvesToNeutralDefaultNotAnError()

    public function testOpenRegisterUnavailableResolvesToNeutralDefault(): void
    {
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

    public function testResolvedOrganisationCarriesItsNameAndOverrides(): void
    {
        $mapper = new class {
            public function findBySlug(string $slug)
            {
                return new class {
                    public function getUuid()
                    {
                        return 'org-uuid-1';
                    }

                    public function getName()
                    {
                        return 'Gemeente X';
                    }
                };
            }
        };

        $overrides = [
            'theme'               => 'utrecht',
            'logo'                => '/apps/portaliq/img/gemeente-x.svg',
            'allowedEmbedOrigins' => ['https://gemeente-x.example'],
            'featureFlags'        => ['aiCompanion' => true],
        ];

        $service = $this->service(mapper: $mapper, overridesJson: json_encode($overrides));
        $config  = $service->resolve('gemeente-x');

        $this->assertSame('Gemeente X', $config['organisationName']);
        $this->assertSame('gemeente-x', $config['organisationSlug']);
        $this->assertSame('utrecht', $config['theme']);
        $this->assertSame('/apps/portaliq/img/gemeente-x.svg', $config['logo']);
        $this->assertSame(['https://gemeente-x.example'], $config['allowedEmbedOrigins']);
        $this->assertSame(['aiCompanion' => true], $config['featureFlags']);

    }//end testResolvedOrganisationCarriesItsNameAndOverrides()

    public function testMalformedAllowedEmbedOriginsDegradesToEmptyDenyList(): void
    {
        $mapper = new class {
            public function findBySlug(string $slug)
            {
                return new class {
                    public function getUuid()
                    {
                        return 'org-uuid-2';
                    }

                    public function getName()
                    {
                        return 'Gemeente Y';
                    }
                };
            }
        };

        // A malformed override (string instead of array) must fail closed to
        // an empty (deny-embed) list, never to something permissive.
        $service = $this->service(mapper: $mapper, overridesJson: json_encode(['allowedEmbedOrigins' => 'https://evil.example']));
        $config  = $service->resolve('gemeente-y');

        $this->assertSame([], $config['allowedEmbedOrigins']);

    }//end testMalformedAllowedEmbedOriginsDegradesToEmptyDenyList()

    /**
     * portal-oidc-broker-login: a configured `eherkenning` provider surfaces
     * in `resolve()`'s secret-free `oidcProviders`, and `resolveOidcConfig()`
     * returns the FULL merged config (issuer/clientId/scopes/claimMap/loaMap)
     * — with the secret coming ONLY from the dedicated sensitive key, never
     * from the presentation-override blob.
     */
    public function testConfiguredProviderSurfacesInOidcProvidersAndResolvesFully(): void
    {
        $overrides = [
            'oidc' => [
                'eherkenning' => [
                    'issuer'   => 'https://broker.example/idp',
                    'clientId' => 'rp-client-1',
                    'scopes'   => ['openid', 'kvk'],
                ],
            ],
        ];

        $service = $this->oidcService(overridesJson: json_encode($overrides), secret: 's3cr3t-value-0000000000');

        $config = $service->resolve('gemeente-x');
        $this->assertSame([['provider' => 'eherkenning', 'label' => 'eHerkenning']], $config['oidcProviders']);
        // The client secret is NEVER present anywhere in the SPA-facing shape.
        $this->assertStringNotContainsString('s3cr3t-value', (string) json_encode($config));

        $full = $service->resolveOidcConfig('gemeente-x', 'eherkenning');
        $this->assertNotNull($full);
        $this->assertSame('https://broker.example/idp', $full['issuer']);
        $this->assertSame('rp-client-1', $full['clientId']);
        $this->assertSame('s3cr3t-value-0000000000', $full['clientSecret']);
        $this->assertSame(['openid', 'kvk'], $full['scopes']);
        $this->assertSame('eherkenning', $full['identityType']);

    }//end testConfiguredProviderSurfacesInOidcProvidersAndResolvesFully()

    public function testUnconfiguredProviderResolvesToNullNotAnError(): void
    {
        $service = $this->oidcService(overridesJson: '{}', secret: '');

        $this->assertNull($service->resolveOidcConfig('gemeente-x', 'digid'));
        $this->assertSame([], $service->resolve('gemeente-x')['oidcProviders']);

    }//end testUnconfiguredProviderResolvesToNullNotAnError()

    public function testResolveOidcConfigFailsClosedWithoutAClientSecret(): void
    {
        $overrides = [
            'oidc' => [
                'digid' => [
                    'issuer'   => 'https://broker.example/idp',
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

    public function testResolveOidcConfigRejectsAnUnknownProviderString(): void
    {
        $service = $this->oidcService(overridesJson: '{}', secret: '');

        $this->assertNull($service->resolveOidcConfig('gemeente-x', 'not-a-real-provider'));

    }//end testResolveOidcConfigRejectsAnUnknownProviderString()

    /**
     * Builds a service against a resolvable "gemeente-x" organisation, with
     * `getValueString` returning `$overridesJson` for the presentation-
     * override key and `$secret` for ANY `oidc_secret_*` key.
     */
    private function oidcService(string $overridesJson, string $secret): PortalOrganisationConfigService
    {
        $mapper = new class {
            public function findBySlug(string $slug)
            {
                return new class {
                    public function getUuid()
                    {
                        return 'org-uuid-x';
                    }

                    public function getName()
                    {
                        return 'Gemeente X';
                    }
                };
            }
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($mapper);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') use ($overridesJson, $secret) {
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

    private function service(?object $mapper=null, ?string $overridesJson=null): PortalOrganisationConfigService
    {
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
