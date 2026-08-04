<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalAuditHook;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the fail-safe audit hook: it is a documented no-op — never an
 * exception to the caller — when the audit service (delivered by
 * portal-session-hardening-v2) is absent, malformed, or itself throws, and it
 * forwards the correct verb/target when the service IS resolvable.
 *
 * @spec openspec/specs/supplier-portal/spec.md#download-emits-an-audit-hook
 */
class PortalAuditHookTest extends TestCase
{

    public function testDownloadIsANoOpWhenAuditServiceIsAbsent(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('not registered'));

        $hook = new PortalAuditHook($container, $this->createMock(LoggerInterface::class));

        // Must not throw.
        $hook->download('s1', 'org-1', 'portaliq', 'exampleDocument', 'd-1');
        $this->addToAssertionCount(1);

    }//end testDownloadIsANoOpWhenAuditServiceIsAbsent()

    public function testDownloadIsANoOpWhenResolvedServiceHasNoRecordMethod(): void
    {
        $malformed = new class {
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($malformed);

        $hook = new PortalAuditHook($container, $this->createMock(LoggerInterface::class));

        $hook->download('s1', 'org-1', 'portaliq', 'exampleDocument', 'd-1');
        $this->addToAssertionCount(1);

    }//end testDownloadIsANoOpWhenResolvedServiceHasNoRecordMethod()

    public function testDownloadNeverPropagatesARecordFailure(): void
    {
        $audit = new class {
            public function record(
                string $verb,
                string $subjectRef,
                string $organisation,
                string $register,
                string $schema,
                string $id
            ): void {
                throw new RuntimeException('write failed');
            }//end record()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($audit);

        $hook = new PortalAuditHook($container, $this->createMock(LoggerInterface::class));

        // The audited action already succeeded; a record() failure must never
        // surface as an exception here.
        $hook->download('s1', 'org-1', 'portaliq', 'exampleDocument', 'd-1');
        $this->addToAssertionCount(1);

    }//end testDownloadNeverPropagatesARecordFailure()

    /**
     * When the audit service IS resolvable, `download()` forwards the verb
     * `download` and the target register/schema/id — the shape
     * portal-session-hardening-v2's `AuditTrailService::record()` expects.
     */
    public function testDownloadForwardsVerbAndTargetWhenServiceIsAvailable(): void
    {
        $audit = new class {
            /**
             * @var array<string, mixed>
             */
            public array $received = [];

            public function record(
                string $verb,
                string $subjectRef,
                string $organisation,
                string $register,
                string $schema,
                string $id
            ): void {
                $this->received = [
                    'verb'         => $verb,
                    'subjectRef'   => $subjectRef,
                    'organisation' => $organisation,
                    'register'     => $register,
                    'schema'       => $schema,
                    'id'           => $id,
                ];
            }//end record()
        };

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($audit);

        $hook = new PortalAuditHook($container, $this->createMock(LoggerInterface::class));
        $hook->download('s1', 'org-1', 'portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(
            [
                'verb'         => 'download',
                'subjectRef'   => 's1',
                'organisation' => 'org-1',
                'register'     => 'portaliq',
                'schema'       => 'exampleDocument',
                'id'           => 'd-1',
            ],
            $audit->received
        );

    }//end testDownloadForwardsVerbAndTargetWhenServiceIsAvailable()
}//end class
