<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MetricsController;
use OCA\Portaliq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portal-notifications-dispatch (T08): the metrics endpoint surfaces two
 * COUNT-ONLY gauges — the number of failed `portalNotification` attempts and
 * the number of `portalAccount` rows flagged `needsAlternativeContact` (the
 * WMEBV notificatieplicht fallback) — never any recipient identity. Also
 * guards the ADR-006 `{app}_` metric-prefix fix (was the leftover
 * `app_template` placeholder).
 *
 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
 */
class MetricsControllerTest extends TestCase
{
    private function objectService(array $portalNotificationRows, array $portalAccountRows): object
    {
        return new class ($portalNotificationRows, $portalAccountRows) {
            private string $schema = '';

            public function __construct(
                private array $portalNotificationRows,
                private array $portalAccountRows,
            ) {
            }

            public function setRegister(string $register): void
            {
            }

            public function setSchema(string $schema): void
            {
                $this->schema = $schema;
            }

            public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array
            {
                return ($this->schema === 'portalNotification') ? $this->portalNotificationRows : $this->portalAccountRows;
            }
        };
    }//end objectService()

    private function controller(object $objectService): MetricsController
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isOpenRegisterAvailable')->willReturn(true);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        return new MetricsController(
            $this->createMock(IRequest::class),
            $settingsService,
            $container,
            $this->createMock(LoggerInterface::class)
        );
    }//end controller()

    public function testMetricsCarryTheAppOwnPrefixAndTheCountOnlyNotificationGauges(): void
    {
        $objectService = $this->objectService(
            portalNotificationRows: [['status' => 'failed'], ['status' => 'failed']],
            portalAccountRows: [['needsAlternativeContact' => true]]
        );

        $response = $this->controller($objectService)->index();
        $body     = $response->render();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        // ADR-006 prefix fix: no more 'app_template_' metrics.
        $this->assertStringNotContainsString('app_template_', $body);
        $this->assertStringContainsString('portaliq_info{', $body);
        $this->assertStringContainsString('portaliq_health_status 1', $body);
        $this->assertStringContainsString('portaliq_notifications_failed_total 2', $body);
        $this->assertStringContainsString('portaliq_accounts_needs_alt_contact 1', $body);

    }//end testMetricsCarryTheAppOwnPrefixAndTheCountOnlyNotificationGauges()

    public function testAnUnreachableObjectServiceDegradesCountsToZero(): void
    {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isOpenRegisterAvailable')->willReturn(false);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

        $controller = new MetricsController(
            $this->createMock(IRequest::class),
            $settingsService,
            $container,
            $this->createMock(LoggerInterface::class)
        );

        $response = $controller->index();
        $body     = $response->render();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertStringContainsString('portaliq_notifications_failed_total 0', $body);
        $this->assertStringContainsString('portaliq_accounts_needs_alt_contact 0', $body);

    }//end testAnUnreachableObjectServiceDegradesCountsToZero()
}//end class
