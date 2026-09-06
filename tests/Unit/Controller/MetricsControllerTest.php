<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\MetricsController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\SettingsService;
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Two concurrently merged count-only metrics additions on the SAME endpoint:
 *
 * - portal-session-hardening-v2 (T10): the portal audit trail COUNT-ONLY, per
 *   verb — never a subject reference, target id, or payload content.
 * - portal-notifications-dispatch (T08): the number of failed
 *   `portalNotification` attempts and the number of `portalAccount` rows
 *   flagged `needsAlternativeContact` (the WMEBV notificatieplicht
 *   fallback) — never any recipient identity. Also guards the ADR-006
 *   `{app}_` metric-prefix fix (was the leftover `app_template` placeholder).
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
 */
class MetricsControllerTest extends TestCase {

	/**
	 * A fake OpenRegister ObjectService returning canned rows per schema, for
	 * the portal-notifications-dispatch count-only gauges.
	 */
	private function objectService(array $portalNotificationRows, array $portalAccountRows): object {
		return new class($portalNotificationRows, $portalAccountRows) {
			private string $schema = '';

			public function __construct(
				private array $portalNotificationRows,
				private array $portalAccountRows,
			) {
			}

			public function setRegister(string $register): void {
			}

			public function setSchema(string $schema): void {
				$this->schema = $schema;
			}

			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				return ($this->schema === 'portalNotification') ? $this->portalNotificationRows : $this->portalAccountRows;
			}
		};
	}//end objectService()

	/**
	 * Build a controller with a canned ObjectService (notification/fallback
	 * counts) and a canned AuditTrailService (audit-entry counts).
	 */
	private function controller(?object $objectService = null, ?AuditTrailService $auditor = null, ?TrafficMetrics $traffic = null): MetricsController {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('isOpenRegisterAvailable')->willReturn(true);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService ?? $this->objectService(portalNotificationRows: [], portalAccountRows: []));

		return new MetricsController(
			$this->createMock(IRequest::class),
			$settingsService,
			$container,
			$this->createMock(LoggerInterface::class),
			($auditor ?? $this->auditorReturning([])),
			($traffic ?? $this->createMock(TrafficMetrics::class))
		);
	}//end controller()

	/**
	 * A canned AuditTrailService returning the given per-verb counts.
	 */
	private function auditorReturning(array $counts): AuditTrailService {
		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->method('countsByVerb')->willReturn($counts);
		return $auditor;
	}//end auditorReturning()

	public function testIndexExposesAuditEntryCountsByVerbAndNothingElse(): void {
		$auditor = $this->auditorReturning(
			[
				'create' => 5,
				'update' => 2,
				'forward' => 0,
				'download' => 3,
				'login' => 7,
				'logout' => 4,
				'refresh' => 1,
			]
		);

		$response = $this->controller(auditor: $auditor)->index();
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

	public function testIndexStillExposesTheExistingHealthAndInfoMetrics(): void {
		// The pre-existing ADR-006 metrics must survive the T10 addition.
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->objectService(portalNotificationRows: [], portalAccountRows: []));

		$controller = new MetricsController(
			$this->createMock(IRequest::class),
			$settings,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->auditorReturning([]),
			$this->createMock(TrafficMetrics::class)
		);

		$body = $controller->index()->render();
		$this->assertStringContainsString('_info{app="portaliq"', $body);
		$this->assertStringContainsString('_health_status 0', $body);

	}//end testIndexStillExposesTheExistingHealthAndInfoMetrics()

	public function testMetricsCarryTheAppOwnPrefixAndTheCountOnlyNotificationGauges(): void {
		$objectService = $this->objectService(
			portalNotificationRows: [['status' => 'failed'], ['status' => 'failed']],
			portalAccountRows: [['needsAlternativeContact' => true]]
		);

		$response = $this->controller(objectService: $objectService)->index();
		$body = $response->render();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// ADR-006 prefix fix: no more 'app_template_' metrics.
		$this->assertStringNotContainsString('app_template_', $body);
		$this->assertStringContainsString('portaliq_info{', $body);
		$this->assertStringContainsString('portaliq_health_status 1', $body);
		$this->assertStringContainsString('portaliq_notifications_failed_total 2', $body);
		$this->assertStringContainsString('portaliq_accounts_needs_alt_contact 1', $body);

	}//end testMetricsCarryTheAppOwnPrefixAndTheCountOnlyNotificationGauges()

	public function testAnUnreachableObjectServiceDegradesCountsToZero(): void {
		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('isOpenRegisterAvailable')->willReturn(false);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('OpenRegister not installed'));

		$controller = new MetricsController(
			$this->createMock(IRequest::class),
			$settingsService,
			$container,
			$this->createMock(LoggerInterface::class),
			$this->auditorReturning([]),
			$this->createMock(TrafficMetrics::class)
		);

		$response = $controller->index();
		$body = $response->render();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('portaliq_notifications_failed_total 0', $body);
		$this->assertStringContainsString('portaliq_accounts_needs_alt_contact 0', $body);

	}//end testAnUnreachableObjectServiceDegradesCountsToZero()
	/**
	 * The traffic collector's counters are exposed: the accepted total and
	 * one refused line PER REASON, so a flood, a misconfigured tag and a
	 * healthy portal are distinguishable from the scrape alone
	 * (portal-traffic-analytics).
	 *
	 * @return void
	 */
	public function testTrafficCountersAreExposedByReason(): void {
		$traffic = $this->createMock(TrafficMetrics::class);
		$traffic->method('acceptedTotal')->willReturn(42);
		$traffic->method('refusedByReason')->willReturn(['bot' => 3, 'event-not-enabled' => 7]);

		$body = $this->controller(traffic: $traffic)->index()->render();

		$this->assertStringContainsString('# TYPE portaliq_traffic_accepted_total counter', $body);
		$this->assertStringContainsString("portaliq_traffic_accepted_total 42\n", $body);
		$this->assertStringContainsString('portaliq_traffic_refused_total{reason="bot"} 3', $body);
		$this->assertStringContainsString('portaliq_traffic_refused_total{reason="event-not-enabled"} 7', $body);
	}//end testTrafficCountersAreExposedByReason()
}//end class
