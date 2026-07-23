<?php

/**
 * Portaliq Portal Audit Hook
 *
 * Places the audit-relevant-event call site for portal actions (currently:
 * document `download`), without depending on the audit ENTRY schema or writer
 * — those are delivered by `portal-session-hardening-v2`'s `AuditTrailService`
 * (`record()`). This class resolves that service from the container BY NAME,
 * exactly the way `PortalFileWriter` resolves OpenRegister's `FileService`, so
 * it compiles and behaves correctly whether or not `AuditTrailService` exists
 * yet: absent (today) it is a documented no-op; once
 * `portal-session-hardening-v2` registers the real service, every existing
 * call site starts recording without any further change here.
 *
 * Failure isolation (mirrors the design note in
 * `portal-session-hardening-v2/design.md`): a `record()` failure — or the
 * service being entirely absent — MUST NOT fail the audited action. The call
 * is always guarded; the caller's response is never affected by the hook.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/supplier-portal/spec.md#download-emits-an-audit-hook
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fail-safe call site for the audit trail's `record()` — a no-op when the
 * audit service is not (yet) registered.
 *
 * @spec openspec/specs/supplier-portal/spec.md#download-emits-an-audit-hook
 */
class PortalAuditHook
{
    /**
     * The audit trail service delivered by portal-session-hardening-v2.
     */
    private const AUDIT_SERVICE = 'OCA\\Portaliq\\Service\\AuditTrailService';

    /**
     * Constructor.
     *
     * @param ContainerInterface $container For resolving the audit service.
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record a `download` event, guarded so an absent/failing audit service
     * never fails the download it is auditing.
     *
     * @param string $subjectRef   The subject the download belongs to.
     * @param string $organisation The subject's tenant.
     * @param string $register     The target register.
     * @param string $schema       The target schema.
     * @param string $id           The target object id.
     *
     * @return void
     *
     * @spec openspec/specs/supplier-portal/spec.md#download-emits-an-audit-hook
     */
    public function download(string $subjectRef, string $organisation, string $register, string $schema, string $id): void
    {
        $service = $this->auditService();
        if ($service === null) {
            return;
        }

        try {
            $service->record(
                verb: 'download',
                subjectRef: $subjectRef,
                organisation: $organisation,
                register: $register,
                schema: $schema,
                id: $id
            );
        } catch (Throwable $e) {
            // Failure isolation: the download already succeeded — an audit
            // gap is logged for reconciliation, never propagated.
            $this->logger->warning('Portaliq: audit hook failed', ['verb' => 'download', 'reason' => $e->getMessage()]);
        }
    }//end download()

    /**
     * Resolve the audit trail service, or null when unavailable (today: it
     * does not exist yet, as documented above).
     *
     * @return object|null
     */
    private function auditService(): ?object
    {
        try {
            $service = $this->container->get(self::AUDIT_SERVICE);
        } catch (Throwable $e) {
            return null;
        }

        if (is_object($service) === true && method_exists($service, 'record') === true) {
            return $service;
        }

        return null;
    }//end auditService()
}//end class
