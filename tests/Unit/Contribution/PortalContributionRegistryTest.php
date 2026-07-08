<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Portal\PortalContributionProvider;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the demo provider + the registry's convention-FQCN discovery, audience
 * filtering, and tolerance of apps without a provider. Contract v2 adds the
 * multi-audience (`getAudiences()`) discovery matrix and the fail-closed
 * `minTrust` manifest filtering inside aggregateFor().
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 * @spec openspec/changes/contract-v2/tasks.md#T2
 */
class PortalContributionRegistryTest extends TestCase
{

    private const PROVIDER_FQCN = 'OCA\\Portaliq\\Portal\\PortalContributionProvider';

    public function testProviderContributesOnlyToSuppliers(): void
    {
        $provider = new PortalContributionProvider();
        $this->assertSame('supplier', $provider->getAudience());
        $this->assertNotNull($provider->getContribution(['audience' => 'supplier']));
        $this->assertNull($provider->getContribution(['audience' => 'client']));

    }//end testProviderContributesOnlyToSuppliers()

    public function testAggregatesMatchingAudienceAndSkipsAppsWithoutProvider(): void
    {
        // 'someotherapp' has no OCA\Someotherapp\Portal\PortalContributionProvider
        // class, so class_exists() skips it before the container is even asked.
        $registry = new PortalContributionRegistry(
            $this->appManager(['portaliq', 'someotherapp']),
            $this->container(new PortalContributionProvider()),
            $this->createMock(LoggerInterface::class)
        );

        $result = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1']);

        $this->assertSame('supplier', $result['audience']);
        $this->assertCount(1, $result['contributions']);
        $this->assertSame('portaliq', $result['contributions'][0]['app']);
        $this->assertSame('org-1', $result['organisation']);

    }//end testAggregatesMatchingAudienceAndSkipsAppsWithoutProvider()

    public function testNonMatchingAudienceYieldsNothing(): void
    {
        $registry = new PortalContributionRegistry(
            $this->appManager(['portaliq']),
            $this->container(new PortalContributionProvider()),
            $this->createMock(LoggerInterface::class)
        );

        $result = $registry->aggregateFor(['audience' => 'client', 'organisation' => 'org-1']);
        $this->assertCount(0, $result['contributions']);

    }//end testNonMatchingAudienceYieldsNothing()

    public function testMultiAudienceProviderIsConsultedForEachListedAudience(): void
    {
        // Duck-typed getAudiences() is preferred; getAudience() would say
        // 'supplier' only, so consulting for 'client' proves the preference.
        $provider = new class {

            public function getAudiences(): array
            {
                return ['client', 'supplier'];
            }

            public function getAudience(): string
            {
                return 'supplier';
            }

            public function getContribution(array $subject): array
            {
                return ['label' => 'Multi', 'collections' => [], 'actions' => []];
            }
        };

        $registry = new PortalContributionRegistry(
            $this->appManager(['portaliq']),
            $this->anyContainer($provider),
            $this->createMock(LoggerInterface::class)
        );

        foreach (['supplier', 'client'] as $audience) {
            $result = $registry->aggregateFor(['audience' => $audience, 'organisation' => 'org-1']);
            $this->assertCount(1, $result['contributions'], "audience '{$audience}' must be served");
        }

        // An audience outside the list is NOT served.
        $result = $registry->aggregateFor(['audience' => 'citizen', 'organisation' => 'org-1']);
        $this->assertCount(0, $result['contributions']);

    }//end testMultiAudienceProviderIsConsultedForEachListedAudience()

    public function testDemoProviderExercisesTheV2Vocabulary(): void
    {
        $provider = new PortalContributionProvider();
        $this->assertSame(['supplier'], $provider->getAudiences());

        $registry = new PortalContributionRegistry(
            $this->appManager(['portaliq']),
            $this->container($provider),
            $this->createMock(LoggerInterface::class)
        );

        // A dev-login (low-trust) supplier: the claim-scoped collection and the
        // placeholder endpoint action are visible, the substantial-gated action
        // is filtered out of the manifest.
        $low         = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'dev-org', 'trust' => 'low']);
        $collections = array_column($low['contributions'][0]['collections'], null, 'id');
        $this->assertArrayHasKey('exampleClaimScoped', $collections);
        $this->assertSame('exampleContactId', $collections['exampleClaimScoped']['scopeClaim']);

        $actions = array_column($low['contributions'][0]['actions'], null, 'id');
        $this->assertArrayHasKey('exampleForward', $actions);
        $this->assertSame('/apps/portaliq/api/health', $actions['exampleForward']['endpoint']);
        $this->assertArrayNotHasKey('exampleTrusted', $actions);

        // A substantial-trust supplier sees the gated action too.
        $substantial = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'dev-org', 'trust' => 'substantial']);
        $actions     = array_column($substantial['contributions'][0]['actions'], null, 'id');
        $this->assertArrayHasKey('exampleTrusted', $actions);

    }//end testDemoProviderExercisesTheV2Vocabulary()

    public function testMinTrustFiltersCollectionsAndActionsFailClosed(): void
    {
        $provider = new class {

            public function getAudiences(): array
            {
                return ['supplier'];
            }

            public function getContribution(array $subject): array
            {
                return [
                    'label'       => 'Trusted',
                    'collections' => [
                        ['id' => 'open', 'register' => 'r', 'schema' => 'a'],
                        ['id' => 'gated', 'register' => 'r', 'schema' => 'b', 'minTrust' => 'substantial'],
                        ['id' => 'typo', 'register' => 'r', 'schema' => 'c', 'minTrust' => 'ultra'],
                    ],
                    'actions'     => [
                        ['id' => 'lowAction', 'type' => 'create', 'register' => 'r', 'schema' => 'a'],
                        ['id' => 'highAction', 'endpoint' => '/apps/x/api/y', 'minTrust' => 'high'],
                    ],
                ];
            }
        };

        $registry = new PortalContributionRegistry(
            $this->appManager(['portaliq']),
            $this->anyContainer($provider),
            $this->createMock(LoggerInterface::class)
        );

        // A low-trust subject (legacy 'dev' normalises to low) sees only the
        // unguarded entries.
        $low = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1', 'trust' => 'dev']);
        $this->assertSame(['open'], array_column($low['contributions'][0]['collections'], 'id'));
        $this->assertSame(['lowAction'], array_column($low['contributions'][0]['actions'], 'id'));

        // A high-trust subject sees everything EXCEPT the unrecognised-minTrust
        // entry — a typo must never widen access (unsatisfiable for everyone).
        $high = $registry->aggregateFor(['audience' => 'supplier', 'organisation' => 'org-1', 'trust' => 'high']);
        $this->assertSame(['open', 'gated'], array_column($high['contributions'][0]['collections'], 'id'));
        $this->assertSame(['lowAction', 'highAction'], array_column($high['contributions'][0]['actions'], 'id'));

    }//end testMinTrustFiltersCollectionsAndActionsFailClosed()

    private function appManager(array $installed): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('getInstalledApps')->willReturn($installed);
        return $mock;

    }//end appManager()

    private function container(PortalContributionProvider $provider): ContainerInterface
    {
        return $this->anyContainer($provider);

    }//end container()

    private function anyContainer(object $provider): ContainerInterface
    {
        $mock = $this->createMock(ContainerInterface::class);
        $mock->method('get')->willReturnCallback(
            function (string $id) use ($provider) {
                if ($id === self::PROVIDER_FQCN) {
                    return $provider;
                }

                throw new RuntimeException('no service: '.$id);
            }
        );
        return $mock;

    }//end anyContainer()

}//end class
