<?php

/**
 * Portaliq Metrics Controller
 *
 * Prometheus-style metrics endpoint (ADR-006).
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/observability/spec.md#REQ-OBS-001
 *   (Illustrative stub per ADR-006 — every app MUST expose `GET /api/metrics`
 *   as Prometheus text, admin auth. Replace the metric values with real data.
 *
 *   Point @spec at the canonical spec under `openspec/specs/`, never at
 *   `openspec/changes/<name>/` — see ConductionNL/.github#228.)
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\SettingsService;
use OCA\Portaliq\Service\Traffic\TrafficMetrics;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Prometheus metrics endpoint for Portaliq (ADR-006).
 *
 * Returns `text/plain; version=0.0.4` with `{app}_` prefixed metrics.
 * MUST include `{app}_health_status` and `{app}_info` per ADR-006.
 * Admin-only (no `@NoAdminRequired`) — ADR-006 mandates admin auth.
 *
 * @spec openspec/specs/observability/spec.md#REQ-OBS-001
 */
class MetricsController extends Controller {
	/**
	 * Metric prefix. Fixed from the leftover app-template placeholder
	 * ('app_template') to this app's own id — ADR-006 requires `{app}_`
	 * prefixed metrics, and every metric name below was carrying the wrong
	 * app's prefix.
	 *
	 * @var string
	 */
	private const METRIC_PREFIX = 'portaliq';

	/**
	 * OpenRegister's object service, resolved lazily (portal-notifications-dispatch
	 * count-only metrics).
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * The register the counted schemas live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * Row cap for the count-only metric reads — bounds the query while
	 * comfortably covering portal-scale volumes; metrics are approximate
	 * gauges, not an exact ledger.
	 */
	private const METRIC_ROW_CAP = 1000;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param SettingsService $settingsService For OpenRegister availability check
	 * @param ContainerInterface $container Resolves OpenRegister's ObjectService for the
	 *                                      count-only notification/fallback metrics.
	 * @param LoggerInterface $logger The logger
	 * @param AuditTrailService $auditor Count-only audit-entry totals by verb
	 *                                   (portal-session-hardening-v2).
	 * @param TrafficMetrics $traffic The collector's accepted and refused
	 *                                counters (portal-traffic-analytics).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/observability/spec.md#REQ-OBS-001
	 */
	public function __construct(
		IRequest $request,
		private SettingsService $settingsService,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private AuditTrailService $auditor,
		private TrafficMetrics $traffic,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Prometheus text exposition. Admin auth per ADR-006.
	 *
	 * @return DataDisplayResponse
	 *
	 * @auth admin-only ADR-006 makes the Prometheus scrape target admin-gated:
	 *       this body counts portalNotification and portalAccount rows, so an
	 *       anonymous scrape would leak tenant volume. Nextcloud expresses
	 *       "admin required" as the ABSENCE of an opt-out attribute, so there
	 *       is no attribute to add here — this tag is the declaration.
	 *
	 * @spec openspec/specs/observability/spec.md#REQ-OBS-001
	 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-collector-must-survive-being-a-public-endpoint
	 */
	public function index(): DataDisplayResponse {
		try {
			$prefix = self::METRIC_PREFIX;
			$healthy = (int)$this->settingsService->isOpenRegisterAvailable();

			// Portal-notifications-dispatch: count-only — NEVER the recipients
			// or subjects themselves, only how many rows exist.
			$failedNotifications = $this->countObjects(schema: 'portalNotification', filters: ['status' => 'failed']);
			$needsAltContact = $this->countObjects(schema: 'portalAccount', filters: ['needsAlternativeContact' => true]);

			$lines = [
				'# HELP ' . $prefix . '_info Static app information',
				'# TYPE ' . $prefix . '_info gauge',
				$prefix . '_info{app="' . Application::APP_ID . '",version="0.1.0"} 1',
				'# HELP ' . $prefix . '_health_status 1 when OpenRegister reachable, 0 otherwise',
				'# TYPE ' . $prefix . '_health_status gauge',
				$prefix . '_health_status ' . $healthy,
				'# HELP ' . $prefix . '_notifications_failed_total Count of failed notification attempts (count-only, no recipient identity).',
				'# TYPE ' . $prefix . '_notifications_failed_total gauge',
				$prefix . '_notifications_failed_total ' . $failedNotifications,
				'# HELP ' . $prefix . '_accounts_needs_alt_contact Count of accounts flagged needsAlternativeContact (WMEBV fallback; count-only).',
				'# TYPE ' . $prefix . '_accounts_needs_alt_contact gauge',
				$prefix . '_accounts_needs_alt_contact ' . $needsAltContact,
			];

			// Count-only portal audit-trail exposure (portal-session-hardening-v2,
			// T10): a total PER VERB, never a subject, target id, or payload.
			$lines[] = '# HELP ' . $prefix . '_audit_entries_total Portal audit-trail entry count by verb';
			$lines[] = '# TYPE ' . $prefix . '_audit_entries_total counter';
			foreach ($this->auditor->countsByVerb() as $verb => $count) {
				$lines[] = $prefix . '_audit_entries_total{verb="' . $verb . '"} ' . $count;
			}

			// The traffic collector (portal-traffic-analytics): how many
			// events were stored, and how many were refused under which
			// reason. The refused counter is what makes a misconfigured
			// client VISIBLE: without it a client whose events are all
			// refused and a working one produce the same 204s and the same
			// quiet dashboard. Counts only, never a portal, a page or a hash.
			$lines[] = '# HELP ' . $prefix . '_traffic_accepted_total Traffic events accepted by the collector.';
			$lines[] = '# TYPE ' . $prefix . '_traffic_accepted_total counter';
			$lines[] = $prefix . '_traffic_accepted_total ' . $this->traffic->acceptedTotal();
			$lines[] = '# HELP ' . $prefix . '_traffic_refused_total Traffic events refused by the collector, by reason.';
			$lines[] = '# TYPE ' . $prefix . '_traffic_refused_total counter';
			foreach ($this->traffic->refusedByReason() as $reason => $count) {
				$lines[] = $prefix . '_traffic_refused_total{reason="' . $reason . '"} ' . $count;
			}

			return new DataDisplayResponse(
				implode("\n", $lines) . "\n",
				Http::STATUS_OK,
				['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
			);
		} catch (\Throwable $e) {
			$this->logger->error('Portaliq: metrics generation failed', ['exception' => $e]);
			return new DataDisplayResponse('', Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try
	}//end index()

	/**
	 * Count objects matching a filter, fail-closed to 0 on any error
	 * (unreachable OpenRegister, malformed result) — a metrics endpoint must
	 * degrade to a safe zero, never throw or leak internals.
	 *
	 * @param string $schema The schema to count.
	 * @param array<string, mixed> $filters The property filters (AND-combined).
	 *
	 * @return int
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
	 */
	private function countObjects(string $schema, array $filters): int {
		try {
			$objectService = $this->container->get(self::OBJECT_SERVICE);
		} catch (Throwable $e) {
			return 0;
		}

		if (is_object($objectService) === false) {
			return 0;
		}

		try {
			$objectService->setRegister(register: self::REGISTER);
			$objectService->setSchema(schema: $schema);
			$rows = $objectService->findAll(
				config: ['filters' => $filters, 'limit' => self::METRIC_ROW_CAP, 'offset' => 0],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->debug('Portaliq: metrics count read failed', ['schema' => $schema, 'reason' => $e->getMessage()]);
			return 0;
		}

		if (is_array($rows) === false) {
			return 0;
		}

		return count($rows);
	}//end countObjects()
}//end class
