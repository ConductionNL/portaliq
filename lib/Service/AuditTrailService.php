<?php

/**
 * Portaliq Audit Trail Service
 *
 * Append-only, portal-owned record of who did what: writes a `portalAuditEntry`
 * for every portal mutation (create/update/forward), every download, and every
 * session event (login/logout/refresh). Backs WMEBV's burden-of-proof-of-
 * delivery requirement (~Awb 2:25) and org wish D7 (audit trails). Also the
 * concrete class `PortalAuditHook` resolves by name from the container — once
 * this class exists, the download hook already placed by portal-document-
 * download starts recording, with no further change to that hook.
 *
 * A record is a FACT (jti, subjectRef, organisation, appId, verb, target
 * register/schema/id, timestamp) and NEVER carries payload content — recording
 * *that* a verb happened against a target, not the data itself, so the audit
 * trail cannot become a second, wider-exposed copy of the domain object.
 *
 * Failure isolation (design.md): a write failure here must NEVER fail the
 * audited action. `record()` catches everything internally, logs the gap for
 * reconciliation, and never throws — callers invoke it fire-and-forget, with
 * no try/catch of their own required.
 *
 * Retention (Archiefwet): per the fleet convention, this service only WRITES
 * entries. Purge/retention is OpenRegister's records-management `_retention`
 * transient (consume, do not rebuild) — an operator/OR-side concern, not
 * enforced here.
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
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Failure-isolated writer for the append-only `portalAuditEntry` trail.
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T08
 */
class AuditTrailService
{
    /**
     * The OpenRegister register the `portalAuditEntry` schema lives in.
     */
    private const REGISTER = 'portaliq';

    /**
     * The OpenRegister schema recording audit entries.
     */
    private const SCHEMA = 'portalAuditEntry';

    /**
     * Constructor.
     *
     * @param PortalObjectWriter $writer Persists audit entries (append-only).
     * @param LoggerInterface    $logger The logger — records a write failure
     *                                   for reconciliation; never rethrown.
     */
    public function __construct(
        private readonly PortalObjectWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Record one audit fact. NEVER throws — a failure is caught, logged, and
     * the audited action is never reversed because its audit entry could not
     * be written.
     *
     * `$jti` and `$appId` are optional because the existing `PortalAuditHook`
     * call site (portal-document-download) does not carry them — the hook's
     * signature is fixed and predates this service; `$appId` defaults to this
     * app's own id (the download hook always records portaliq's own action)
     * and an absent `$jti` simply records an empty token id.
     *
     * @param string $verb         One of create|update|forward|download|login|logout|refresh.
     * @param string $subjectRef   The subject the event belongs to.
     * @param string $organisation The subject's tenant.
     * @param string $register     The target register (or a stand-in namespace for
     *                             non-object events such as a forwarded action's appId).
     * @param string $schema       The target schema (or a stand-in for the action id).
     * @param string $id           The target object id (may be empty for session events).
     * @param string $jti          The acting/affected session's token id, when known.
     * @param string $appId        The contributing app id recording the entry.
     *
     * @return void
     *
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T08
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T09
     */
    public function record(
        string $verb,
        string $subjectRef,
        string $organisation,
        string $register,
        string $schema,
        string $id,
        string $jti='',
        string $appId=Application::APP_ID
    ): void {
        try {
            $this->writer->createObject(
                register: self::REGISTER,
                schema: self::SCHEMA,
                scopeField: '',
                subjectRef: $subjectRef,
                organisation: $organisation,
                data: [
                    'jti'          => $jti,
                    'subjectRef'   => $subjectRef,
                    'organisation' => $organisation,
                    'appId'        => $appId,
                    'verb'         => $verb,
                    'register'     => $register,
                    'schema'       => $schema,
                    'targetId'     => $id,
                    'timestamp'    => (new DateTimeImmutable())->format(DATE_ATOM),
                ]
            );
        } catch (Throwable $e) {
            // Failure isolation (design.md): the audited action already
            // happened — the gap is logged for reconciliation, never
            // propagated to the caller.
            $this->logger->warning('Portaliq: audit record failed', ['verb' => $verb, 'reason' => $e->getMessage()]);
        }//end try
    }//end record()

    /**
     * Count-only exposure for `MetricsController` (never subjects/targets/
     * payload): the number of `portalAuditEntry` rows per verb. Degrades to an
     * all-zero map when OpenRegister is unavailable — metrics generation must
     * never fail because the audit register is unreachable.
     *
     * @return array<string, int> Counts keyed by verb.
     *
     * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
     */
    public function countsByVerb(): array
    {
        $verbs  = ['create', 'update', 'forward', 'download', 'login', 'logout', 'refresh'];
        $counts = [];
        foreach ($verbs as $verb) {
            $counts[$verb] = $this->writer->countObjects(
                register: self::REGISTER,
                schema: self::SCHEMA,
                filters: ['verb' => $verb]
            );
        }

        return $counts;
    }//end countsByVerb()
}//end class
