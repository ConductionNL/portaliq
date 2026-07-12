<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

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
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.2
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#1.3
 * @spec openspec/changes/portal-white-label-runtime-config/tasks.md#3.1
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
            $this->createMock(LoggerInterface::class)
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
            $this->createMock(LoggerInterface::class)
        );

    }//end service()

}//end class
