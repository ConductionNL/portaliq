<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MetricsController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * portal-session-hardening-v2 T10: the metrics endpoint surfaces the portal
 * audit trail COUNT-ONLY, per verb — never a subject reference, target id,
 * or payload content.
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 */
class MetricsControllerTest extends TestCase
{

    public function testIndexExposesAuditEntryCountsByVerbAndNothingElse(): void
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('isOpenRegisterAvailable')->willReturn(true);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->method('countsByVerb')->willReturn(
            [
                'create'   => 5,
                'update'   => 2,
                'forward'  => 0,
                'download' => 3,
                'login'    => 7,
                'logout'   => 4,
                'refresh'  => 1,
            ]
        );

        $controller = new MetricsController(
            $this->createMock(IRequest::class),
            $settings,
            $this->createMock(LoggerInterface::class),
            $auditor
        );

        $response = $controller->index();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $body = $response->render();
        $this->assertStringContainsString('_audit_entries_total{verb="create"} 5', $body);
        $this->assertStringContainsString('_audit_entries_total{verb="login"} 7', $body);
        $this->assertStringContainsString('_audit_entries_total{verb="refresh"} 1', $body);

        // Count-only: no subject reference, target identifier, or payload
        // content ever appears in the exposition text.
        $this->assertStringNotContainsString('subjectRef', $body);
        $this->assertStringNotContainsString('s1', $body);
        $this->assertStringNotContainsString('organisation', $body);

    }//end testIndexExposesAuditEntryCountsByVerbAndNothingElse()

    public function testIndexStillExposesTheExistingHealthAndInfoMetrics(): void
    {
        // The pre-existing ADR-006 metrics must survive the T10 addition.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('isOpenRegisterAvailable')->willReturn(false);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->method('countsByVerb')->willReturn([]);

        $controller = new MetricsController(
            $this->createMock(IRequest::class),
            $settings,
            $this->createMock(LoggerInterface::class),
            $auditor
        );

        $body = $controller->index()->render();
        $this->assertStringContainsString('_info{app="portaliq"', $body);
        $this->assertStringContainsString('_health_status 0', $body);

    }//end testIndexStillExposesTheExistingHealthAndInfoMetrics()
}//end class
