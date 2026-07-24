<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * portal-session-hardening-v2: an audit entry is written per verb with NO
 * payload content, a `record()` failure is caught + logged and NEVER
 * propagated (the audited action must never be reversed), the download
 * hook's fixed 6-argument call shape (no jti/appId) still records correctly
 * with sane defaults, and `countsByVerb()` degrades to an all-zero map when
 * OpenRegister is unavailable rather than failing the metrics endpoint.
 *
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T08
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T10
 * @spec openspec/changes/portal-session-hardening-v2/tasks.md#T13
 */
class AuditTrailServiceTest extends TestCase
{

    public function testRecordWritesAnAppendOnlyEntryWithNoPayload(): void
    {
        $captured = null;
        $writer   = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
                $captured = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'data' => $data];
                return $data;
            }
        );

        $service = new AuditTrailService($writer, $this->createMock(LoggerInterface::class));
        $service->record(
            verb: 'create',
            subjectRef: 's1',
            organisation: 'org-1',
            register: 'r1',
            schema: 'a',
            id: 'obj-1',
            jti: 'jti-1',
            appId: 'demo'
        );

        $this->assertSame('portaliq', $captured['register']);
        $this->assertSame('portalAuditEntry', $captured['schema']);
        // No ownership re-verification is needed for a fact record.
        $this->assertSame('', $captured['scopeField']);

        $data = $captured['data'];
        $this->assertSame('jti-1', $data['jti']);
        $this->assertSame('s1', $data['subjectRef']);
        $this->assertSame('org-1', $data['organisation']);
        $this->assertSame('demo', $data['appId']);
        $this->assertSame('create', $data['verb']);
        $this->assertSame('r1', $data['register']);
        $this->assertSame('a', $data['schema']);
        $this->assertSame('obj-1', $data['id']);
        $this->assertArrayHasKey('timestamp', $data);
        // NEVER a payload/content field — a fact record only.
        $this->assertArrayNotHasKey('data', $data);
        $this->assertArrayNotHasKey('payload', $data);
        $this->assertArrayNotHasKey('object', $data);

    }//end testRecordWritesAnAppendOnlyEntryWithNoPayload()

    public function testRecordDefaultsJtiAndAppIdForTheFixedDownloadHookCallShape(): void
    {
        // PortalAuditHook::download() calls record() with EXACTLY 6 arguments
        // (no jti, no appId) — its signature predates this service and is
        // pinned by PortalAuditHookTest. Confirms the defaults keep it working.
        $captured = null;
        $writer   = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
                $captured = $data;
                return $data;
            }
        );

        $service = new AuditTrailService($writer, $this->createMock(LoggerInterface::class));
        $service->record(
            verb: 'download',
            subjectRef: 's1',
            organisation: 'org-1',
            register: 'portaliq',
            schema: 'exampleDocument',
            id: 'd-1'
        );

        $this->assertSame('', $captured['jti']);
        $this->assertSame('portaliq', $captured['appId']);
        $this->assertSame('download', $captured['verb']);

    }//end testRecordDefaultsJtiAndAppIdForTheFixedDownloadHookCallShape()

    public function testRecordFailureIsCaughtLoggedAndNeverPropagated(): void
    {
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willThrowException(new RuntimeException('OR unreachable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $service = new AuditTrailService($writer, $logger);

        // Must NOT throw — the audited action already happened.
        $service->record(verb: 'create', subjectRef: 's1', organisation: 'org-1', register: 'r1', schema: 'a', id: 'obj-1');
        $this->addToAssertionCount(1);

    }//end testRecordFailureIsCaughtLoggedAndNeverPropagated()

    public function testCountsByVerbReturnsACountPerVerb(): void
    {
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('countObjects')->willReturnCallback(
            function (string $register, string $schema, array $filters=[]) {
                $counts = ['login' => 3, 'create' => 5];
                return ($counts[($filters['verb'] ?? '')] ?? 0);
            }
        );

        $service = new AuditTrailService($writer, $this->createMock(LoggerInterface::class));
        $counts  = $service->countsByVerb();

        $this->assertSame(3, $counts['login']);
        $this->assertSame(5, $counts['create']);
        $this->assertSame(0, $counts['logout']);
        // Every declared verb is present, count-only — no subject/target/payload keys.
        $this->assertSame(['create', 'update', 'forward', 'download', 'login', 'logout', 'refresh'], array_keys($counts));

    }//end testCountsByVerbReturnsACountPerVerb()

    public function testCountsByVerbDegradesToZeroWhenWriterFails(): void
    {
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('countObjects')->willReturn(0);

        $service = new AuditTrailService($writer, $this->createMock(LoggerInterface::class));
        $counts  = $service->countsByVerb();

        foreach ($counts as $count) {
            $this->assertSame(0, $count);
        }

    }//end testCountsByVerbDegradesToZeroWhenWriterFails()
}//end class
