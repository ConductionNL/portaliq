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
 * filtering, and tolerance of apps without a provider.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T04
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

    private function appManager(array $installed): IAppManager
    {
        $mock = $this->createMock(IAppManager::class);
        $mock->method('getInstalledApps')->willReturn($installed);
        return $mock;

    }//end appManager()

    private function container(PortalContributionProvider $provider): ContainerInterface
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

    }//end container()

}//end class
