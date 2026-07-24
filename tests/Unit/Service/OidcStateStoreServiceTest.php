<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Service\OidcStateStoreService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;

/**
 * portal-oidc-broker-login T02: single-use, TTL-bounded OIDC `state` storage
 * — the CSRF/replay guard the callback depends on. An unknown state, a
 * replayed (already-`used`) state, and an expired state all fail closed to
 * null; a state is marked `used` as part of a successful `consume()`, so a
 * second consumption of the SAME state — the replay case — also fails.
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T02
 * @spec openspec/specs/supplier-portal/spec.md#every-validation-failure-is-an-identical-generic-error
 */
class OidcStateStoreServiceTest extends TestCase
{

    public function testCreateThenConsumeReturnsTheStoredPayload(): void
    {
        $service = $this->serviceWithStore();

        $created = $service->create(state: 'state-1', nonce: 'nonce-1', codeVerifier: 'verifier-1', org: 'gemeente-x', provider: 'eherkenning', returnTo: '/portal');
        $this->assertTrue($created);

        $consumed = $service->consume(state: 'state-1');
        $this->assertSame(
            ['nonce' => 'nonce-1', 'codeVerifier' => 'verifier-1', 'org' => 'gemeente-x', 'provider' => 'eherkenning', 'returnTo' => '/portal'],
            $consumed
        );

    }//end testCreateThenConsumeReturnsTheStoredPayload()

    public function testConsumingAnUnknownStateFailsClosed(): void
    {
        $service = $this->serviceWithStore();

        $this->assertNull($service->consume(state: 'never-created'));

    }//end testConsumingAnUnknownStateFailsClosed()

    public function testConsumingTheSameStateTwiceFailsClosedTheSecondTime(): void
    {
        $service = $this->serviceWithStore();

        $service->create(state: 'state-1', nonce: 'n', codeVerifier: 'v', org: 'o', provider: 'digid', returnTo: '/portal');

        $this->assertNotNull($service->consume(state: 'state-1'));
        // Replay — the SAME state a second time must fail closed.
        $this->assertNull($service->consume(state: 'state-1'));

    }//end testConsumingTheSameStateTwiceFailsClosedTheSecondTime()

    public function testAnExpiredStateFailsClosedEvenIfNeverConsumed(): void
    {
        $store   = [];
        $service = $this->serviceWithStore($store);

        $service->create(state: 'state-1', nonce: 'n', codeVerifier: 'v', org: 'o', provider: 'digid', returnTo: '/portal');
        // Backdate expiresAt into the past — simulates a stale round trip.
        $uuid                      = array_key_first($store);
        $store[$uuid]['expiresAt'] = (new DateTimeImmutable())->sub(new DateInterval('PT10M'))->format(DATE_ATOM);

        $this->assertNull($service->consume(state: 'state-1'));

    }//end testAnExpiredStateFailsClosedEvenIfNeverConsumed()

    public function testAnEmptyStateIsRejectedOnBothPaths(): void
    {
        $service = $this->serviceWithStore();

        $this->assertFalse($service->create(state: '', nonce: 'n', codeVerifier: 'v', org: 'o', provider: 'digid', returnTo: '/portal'));
        $this->assertNull($service->consume(state: ''));

    }//end testAnEmptyStateIsRejectedOnBothPaths()

    public function testAWriteFailureAtCreateFailsClosed(): void
    {
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturn(null);

        $reader  = $this->createMock(PortalObjectReader::class);
        $service = new OidcStateStoreService($writer, $reader);

        $this->assertFalse($service->create(state: 's', nonce: 'n', codeVerifier: 'v', org: 'o', provider: 'digid', returnTo: '/portal'));

    }//end testAWriteFailureAtCreateFailsClosed()

    /**
     * Build a service backed by a tiny in-memory OR fake — the SAME pattern
     * used by PortalSessionServiceTest. When a test needs to tamper with a
     * stored row directly (e.g. backdating `expiresAt`), it passes its own
     * `$store` variable BY REFERENCE so the fake writer/reader operate on
     * that exact array.
     *
     * @param array<string, array<string, mixed>> $store The backing store
     *                                                     (by reference — a
     *                                                     caller may inspect/
     *                                                     mutate it after calls).
     *
     * @return OidcStateStoreService
     */
    private function serviceWithStore(array &$store=[]): OidcStateStoreService
    {
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$store) {
                $uuid         = 'uuid-'.(count($store) + 1);
                $data['uuid'] = $uuid;
                $store[$uuid] = $data;
                return $data;
            }
        );
        $writer->method('updateObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$store) {
                if (isset($store[$id]) === false) {
                    return null;
                }

                $store[$id] = array_merge($store[$id], $data);
                return $store[$id];
            }
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readCollection')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation='', int $limit=200) use (&$store) {
                $matches = [];
                foreach ($store as $row) {
                    if (($row[$scopeField] ?? null) === $subjectRef) {
                        $matches[] = $row;
                    }
                }

                return $matches;
            }
        );

        return new OidcStateStoreService($writer, $reader);

    }//end serviceWithStore()

}//end class
