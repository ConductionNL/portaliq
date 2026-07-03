<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ExampleContributionProvider;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the example provider + the registry's audience-filtered aggregation and
 * its tolerance of apps that register no provider.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
 */
class PortalContributionRegistryTest extends TestCase
{

    private const ALIAS = 'OCA\\Portaliq\\Contribution\\IPortalContributionProvider::portaliq';

    public function testExampleProviderContributesOnlyToSuppliers(): void
    {
        $provider = new ExampleContributionProvider();
        $this->assertSame('supplier', $provider->getAudience());
        $this->assertNotNull($provider->getContribution(['audience' => 'supplier']));
        $this->assertNull($provider->getContribution(['audience' => 'client']));

    }//end testExampleProviderContributesOnlyToSuppliers()

    public function testAggregatesMatchingAudienceAndSkipsAppsWithoutProvider(): void
    {
        $registry = new PortalContributionRegistry(
            $this->appManager(['portaliq', 'someotherapp']),
            $this->container(new ExampleContributionProvider()),
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
            $this->container(new ExampleContributionProvider()),
            $this->createMock(LoggerInterface::class)
        );

        $result = $registry->aggregateFor(['audience' => 'client', 'organisation' => 'org-1']);
        $this->assertCount(0, $result['contributions']);

    }//end testNonMatchingAudienceYieldsNothing()

    private function appManager(array $enabled): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('getInstalledApps')->willReturn($enabled);
        return $mock;

    }//end appManager()

    private function container(ExampleContributionProvider $provider): ContainerInterface
    {
        $mock = $this->createMock(ContainerInterface::class);
        $mock->method('get')->willReturnCallback(
            function (string $id) use ($provider) {
                if ($id === self::ALIAS) {
                    return $provider;
                }

                throw new RuntimeException('no provider: '.$id);
            }
        );
        return $mock;

    }//end container()

}//end class
