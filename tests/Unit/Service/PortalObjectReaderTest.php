<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OR-backed reader: it degrades to empty without OpenRegister, filters
 * on the scope field, and re-verifies every row so a foreign-subject object can
 * never leak. Contract v2 adds the server-side scopeClaim resolution matrix
 * (bare + dotted addressing, fail-closed empty on absent/malformed claims with
 * NO unscoped query) and the one-hop `via` join (dot-path per-row verification,
 * target membership, fail-closed on invalid/nested declarations).
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 * @spec openspec/changes/contract-v2/tasks.md#T5
 * @spec openspec/changes/contract-v2/tasks.md#T6
 */
class PortalObjectReaderTest extends TestCase
{

    private const OS = 'OCA\\OpenRegister\\Service\\ObjectService';

    public function testReturnsEmptyWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OR not installed'));

        $reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class));
        $this->assertSame([], $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1'));

    }//end testReturnsEmptyWhenOpenRegisterUnavailable()

    public function testFiltersOnScopeAndDropsForeignRows(): void
    {
        $objectService = new class {
            /** @var array<string,mixed> */
            public array $received = [];

            public string $register = '';

            public string $schema = '';

            public function setRegister(string $register): self
            {
                $this->register = $register;
                return $this;
            }

            public function setSchema(string $schema): self
            {
                $this->schema = $schema;
                return $this;
            }

            public bool $rbac = true;

            public bool $multitenancy = true;

            /**
             * @param array<string,mixed> $config
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array
            {
                $this->received     = $config;
                $this->rbac         = $_rbac;
                $this->multitenancy = $_multitenancy;
                // OR mistakenly returns a foreign row too — the reader must drop it.
                return [
                    ['subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine'],
                    ['subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Not mine'],
                ];
            }
        };

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection('portaliq', 'exampleDocument', 'subjectRef', 's1', 'org-1');

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['title']);
        // register/schema are set via the setters, NOT leaked into filters.
        $this->assertSame('portaliq', $objectService->register);
        $this->assertSame('exampleDocument', $objectService->schema);
        $this->assertArrayNotHasKey('register', $objectService->received['filters']);
        // organisation is a multitenancy field, NOT an OR filter (it is only a
        // per-row check), so it must not appear in the query filters.
        $this->assertArrayNotHasKey('organisation', $objectService->received['filters']);
        $this->assertSame('s1', $objectService->received['filters']['subjectRef']);
        // Portal reads bypass OR's NC-user RBAC/multitenancy — Portaliq scopes.
        $this->assertFalse($objectService->rbac);
        $this->assertFalse($objectService->multitenancy);

    }//end testFiltersOnScopeAndDropsForeignRows()

    public function testClaimScopedReadResolvesTheClaimServerSide(): void
    {
        $objectService = $this->objectService(
            [
                'portalAccount' => [
                    // A foreign account row OR mistakenly returns is dropped by
                    // the per-row verification before any claim is read.
                    ['subjectRef' => 'someone-else', 'organisation' => 'org-1', 'claims' => ['pipelinq' => ['linkedContactId' => 'uuid-foreign']]],
                    ['subjectRef' => 's1', 'organisation' => 'org-1', 'claims' => ['pipelinq' => ['linkedContactId' => 'uuid-c1']]],
                ],
                'crmDeal'       => [
                    ['contact' => 'uuid-c1', 'organisation' => 'org-1', 'title' => 'Mine'],
                    ['contact' => 'uuid-other', 'organisation' => 'org-1', 'title' => 'Not mine'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'pipelinq',
            schema: 'crmDeal',
            scopeField: 'contact',
            subjectRef: 's1',
            organisation: 'org-1',
            limit: 200,
            scopeClaim: 'linkedContactId',
            contributingApp: 'pipelinq',
            via: null,
            audience: 'supplier'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['title']);
        // First query: the subject's OWN portalAccount, scoped by subjectRef +
        // audience; second: the collection, filtered by the RESOLVED claim.
        $this->assertCount(2, $objectService->calls);
        $this->assertSame('portalAccount', $objectService->calls[0]['schema']);
        $this->assertSame('s1', $objectService->calls[0]['config']['filters']['subjectRef']);
        $this->assertSame('supplier', $objectService->calls[0]['config']['filters']['audience']);
        $this->assertSame('uuid-c1', $objectService->calls[1]['config']['filters']['contact']);

    }//end testClaimScopedReadResolvesTheClaimServerSide()

    public function testDottedScopeClaimResolvesInTheExplicitNamespace(): void
    {
        $objectService = $this->objectService(
            [
                'portalAccount' => [
                    ['subjectRef' => 's1', 'claims' => ['otherapp' => ['contactId' => 'uuid-x']]],
                ],
                'crmDeal'       => [
                    ['contact' => 'uuid-x', 'title' => 'Cross-app'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'pipelinq',
            schema: 'crmDeal',
            scopeField: 'contact',
            subjectRef: 's1',
            organisation: '',
            limit: 200,
            scopeClaim: 'otherapp.contactId',
            contributingApp: 'pipelinq',
            via: null,
            audience: 'supplier'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('Cross-app', $rows[0]['title']);

    }//end testDottedScopeClaimResolvesInTheExplicitNamespace()

    public function testAbsentClaimYieldsEmptyWithoutAnUnscopedQuery(): void
    {
        $objectService = $this->objectService(
            [
                // The account exists but carries NO claim for the address.
                'portalAccount' => [
                    ['subjectRef' => 's1', 'claims' => []],
                ],
                'crmDeal'       => [
                    ['contact' => 'uuid-c1', 'title' => 'Must never surface'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'pipelinq',
            schema: 'crmDeal',
            scopeField: 'contact',
            subjectRef: 's1',
            organisation: '',
            limit: 200,
            scopeClaim: 'linkedContactId',
            contributingApp: 'pipelinq',
            via: null,
            audience: 'supplier'
        );

        $this->assertSame([], $rows);
        // Only the portalAccount lookup ran — the collection was NEVER queried.
        $this->assertCount(1, $objectService->calls);
        $this->assertSame('portalAccount', $objectService->calls[0]['schema']);

    }//end testAbsentClaimYieldsEmptyWithoutAnUnscopedQuery()

    public function testMalformedScopeClaimYieldsEmptyWithoutAnyQuery(): void
    {
        $objectService = $this->objectService(['crmDeal' => [['contact' => 'x']]]);

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));

        foreach (['Bad Claim!', '.leadingDot', 'trailing.', 'UPPER.case', '9starts.withDigit'] as $malformed) {
            $rows = $reader->readCollection(
                register: 'pipelinq',
                schema: 'crmDeal',
                scopeField: 'contact',
                subjectRef: 's1',
                organisation: '',
                limit: 200,
                scopeClaim: $malformed,
                contributingApp: 'pipelinq',
                via: null,
                audience: 'supplier'
            );
            $this->assertSame([], $rows, "scopeClaim '{$malformed}' must fail closed");
        }

        $this->assertCount(0, $objectService->calls);

    }//end testMalformedScopeClaimYieldsEmptyWithoutAnyQuery()

    public function testViaJoinReturnsOnlyVerifiedTargets(): void
    {
        $objectService = $this->objectService(
            [
                'rol'  => [
                    // Verified: dot-path matches the subject; scalar target.
                    ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
                    // Foreign join row — dropped even though OR returned it.
                    ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-2'], 'zaak' => 'z-2'],
                    // Verified: array-form targetField (empty entries skipped).
                    ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => ['z-3', '']],
                ],
                'zaak' => [
                    ['uuid' => 'z-1', 'title' => 'Mine'],
                    ['id' => 'z-2', 'title' => 'Foreign case'],
                    ['@self' => ['uuid' => 'z-3'], 'title' => 'Envelope id'],
                    ['uuid' => 'z-9', 'title' => 'Unreferenced'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'zaken',
            schema: 'zaak',
            scopeField: 'irrelevantForVia',
            subjectRef: 'bsn-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'zaakafhandelapp',
            via: [
                'register'    => 'zaken',
                'schema'      => 'rol',
                'scopeField'  => 'betrokkeneIdentificatie.inpBsn',
                'targetField' => 'zaak',
            ],
            audience: 'citizen'
        );

        $this->assertSame(['Mine', 'Envelope id'], array_column($rows, 'title'));
        // Join pre-pass: filtered query-side (best-effort), row-capped at 500.
        $this->assertSame('rol', $objectService->calls[0]['schema']);
        $this->assertSame('bsn-1', $objectService->calls[0]['config']['filters']['betrokkeneIdentificatie.inpBsn']);
        $this->assertSame(500, $objectService->calls[0]['config']['limit']);
        // Both passes bypass OR RBAC/multitenancy — portaliq is the boundary.
        $this->assertFalse($objectService->calls[0]['rbac']);
        $this->assertFalse($objectService->calls[1]['rbac']);

    }//end testViaJoinReturnsOnlyVerifiedTargets()

    public function testInvalidOrNestedViaFailsClosedWithWarning(): void
    {
        $invalidShapes = [
            'not-an-array',
            ['register' => 'zaken', 'schema' => 'rol', 'scopeField' => 'x'],
            ['register' => 'zaken', 'schema' => 'rol', 'scopeField' => '', 'targetField' => 'zaak'],
            // One hop maximum: a nested via is contract-law invalid.
            [
                'register'    => 'zaken',
                'schema'      => 'rol',
                'scopeField'  => 'x',
                'targetField' => 'zaak',
                'via'         => ['register' => 'deeper'],
            ],
        ];

        foreach ($invalidShapes as $via) {
            $objectService = $this->objectService(['zaak' => [['uuid' => 'z-1', 'title' => 'Never']]]);
            $logger        = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())->method('warning');

            $reader = new PortalObjectReader($this->container($objectService), $logger);
            $rows   = $reader->readCollection(
                register: 'zaken',
                schema: 'zaak',
                scopeField: 'x',
                subjectRef: 'bsn-1',
                organisation: '',
                limit: 200,
                scopeClaim: '',
                contributingApp: 'zaakafhandelapp',
                via: $via,
                audience: 'citizen'
            );

            $this->assertSame([], $rows);
            $this->assertCount(0, $objectService->calls);
        }

    }//end testInvalidOrNestedViaFailsClosedWithWarning()

    public function testViaWithEmptyVerifiedJoinSetYieldsEmptyWithoutTargetRead(): void
    {
        $objectService = $this->objectService(
            [
                'rol'  => [],
                'zaak' => [['uuid' => 'z-1', 'title' => 'Never']],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'zaken',
            schema: 'zaak',
            scopeField: 'x',
            subjectRef: 'bsn-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'zaakafhandelapp',
            via: [
                'register'    => 'zaken',
                'schema'      => 'rol',
                'scopeField'  => 'inpBsn',
                'targetField' => 'zaak',
            ],
            audience: 'citizen'
        );

        $this->assertSame([], $rows);
        // Only the join pre-pass ran; the target read was skipped.
        $this->assertCount(1, $objectService->calls);
        $this->assertSame('rol', $objectService->calls[0]['schema']);

    }//end testViaWithEmptyVerifiedJoinSetYieldsEmptyWithoutTargetRead()

    /**
     * An ObjectService stand-in serving canned rows per schema and recording
     * every call (register/schema context, config, rbac/multitenancy flags).
     */
    private function objectService(array $returnsPerSchema): object
    {
        return new class ($returnsPerSchema) {

            /** @var array<int,array<string,mixed>> */
            public array $calls = [];

            private string $register = '';

            private string $schema = '';

            public function __construct(private array $returns)
            {
            }

            public function setRegister(string $register): self
            {
                $this->register = $register;
                return $this;
            }

            public function setSchema(string $schema): self
            {
                $this->schema = $schema;
                return $this;
            }

            /**
             * @param array<string,mixed> $config
             *
             * @return array<int,array<string,mixed>>
             */
            public function findAll(array $config, bool $_rbac=true, bool $_multitenancy=true): array
            {
                $this->calls[] = [
                    'register'     => $this->register,
                    'schema'       => $this->schema,
                    'config'       => $config,
                    'rbac'         => $_rbac,
                    'multitenancy' => $_multitenancy,
                ];
                return ($this->returns[$this->schema] ?? []);
            }
        };

    }//end objectService()

    private function container(object $objectService): ContainerInterface
    {
        $mock = $this->createMock(ContainerInterface::class);
        $mock->method('get')->willReturnCallback(
            function (string $id) use ($objectService) {
                if ($id === self::OS) {
                    return $objectService;
                }

                throw new RuntimeException('no service: '.$id);
            }
        );
        return $mock;

    }//end container()

}//end class
