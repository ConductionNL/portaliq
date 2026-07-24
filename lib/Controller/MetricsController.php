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
 * @spec openspec/changes/example-change/tasks.md#task-8
 *   (Illustrative stub per ADR-006 — every app MUST expose `GET /api/metrics`
 *   as Prometheus text, admin auth. Replace the metric values with real data.)
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Prometheus metrics endpoint for Portaliq (ADR-006).
 *
 * Returns `text/plain; version=0.0.4` with `{app}_` prefixed metrics.
 * MUST include `{app}_health_status` and `{app}_info` per ADR-006.
 * Admin-only (no `@NoAdminRequired`) — ADR-006 mandates admin auth.
 */
class MetricsController extends Controller
{
    /**
     * Metric prefix.
     *
     * @var string
     */
    private const METRIC_PREFIX = 'app_template';

    /**
     * Constructor.
     *
     * @param IRequest          $request         The request object
     * @param SettingsService   $settingsService For OpenRegister availability check
     * @param LoggerInterface   $logger          The logger
     * @param AuditTrailService $auditor         Count-only audit-entry totals by verb
     *                                           (portal-session-hardening-v2).
     *
     * @return void
     *
     * @spec openspec/changes/example-change/tasks.md#task-8
     */
    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private LoggerInterface $logger,
        private AuditTrailService $auditor,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Prometheus text exposition. Admin auth per ADR-006.
     *
     * @return DataDisplayResponse
     *
     * @spec openspec/changes/example-change/tasks.md#task-8
     */
    public function index(): DataDisplayResponse
    {
        try {
            $prefix  = self::METRIC_PREFIX;
            $healthy = (int) $this->settingsService->isOpenRegisterAvailable();

            $lines = [
                '# HELP '.$prefix.'_info Static app information',
                '# TYPE '.$prefix.'_info gauge',
                $prefix.'_info{app="'.Application::APP_ID.'",version="0.1.0"} 1',
                '# HELP '.$prefix.'_health_status 1 when OpenRegister reachable, 0 otherwise',
                '# TYPE '.$prefix.'_health_status gauge',
                $prefix.'_health_status '.$healthy,
            ];

            // Count-only portal audit-trail exposure (portal-session-hardening-v2,
            // T10): a total PER VERB, never a subject, target id, or payload.
            $lines[] = '# HELP '.$prefix.'_audit_entries_total Portal audit-trail entry count by verb';
            $lines[] = '# TYPE '.$prefix.'_audit_entries_total counter';
            foreach ($this->auditor->countsByVerb() as $verb => $count) {
                $lines[] = $prefix.'_audit_entries_total{verb="'.$verb.'"} '.$count;
            }

            return new DataDisplayResponse(
                implode("\n", $lines)."\n",
                Http::STATUS_OK,
                ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
            );
        } catch (\Throwable $e) {
            $this->logger->error('Portaliq: metrics generation failed', ['exception' => $e]);
            return new DataDisplayResponse('', Http::STATUS_INTERNAL_SERVER_ERROR);
        }//end try
    }//end index()
}//end class
