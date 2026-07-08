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
 * target membership, fail-closed on invalid/nested declarations). The
 * field-projection matrix proves the read-side `fields` whitelist: declared
 * properties + identifiers only, applied after verification on the direct AND
 * via paths, full rows without a declaration, identifiers-only on a malformed
 * one, and the single-row (detail) primitive `projectRow()` directly. The
 * reverse-scope-join matrix (v2.2) proves `via.match: 'scopeField'`: outer
 * rows matched by the collection's own scope field (scalar and array-element)
 * against the subject-resolved target set, empty set → zero rows, absent/null
 * scopeField excluded, tenant still enforced on the outer row, malformed
 * match fails the via closed, forward `match: 'id'` (explicit and absent)
 * unchanged, and projection applied to reverse-joined rows.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T05
 * @spec openspec/changes/contract-v2/tasks.md#T5
 * @spec openspec/changes/contract-v2/tasks.md#T6
 * @spec openspec/changes/field-projection/tasks.md#T1
 * @spec openspec/changes/reverse-scope-join/tasks.md#T1
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
     * Reverse join happy path (scholiq parent): the subject (a guardian)
     * resolves through the join to a set of learner refs, then outer
     * `gradeEntry` rows are kept when THEIR OWN `learnerRef` (the collection's
     * scopeField) is in that set — not when their id is. A foreign learner's
     * grade and an unrelated grade are both dropped even though OR returned
     * them.
     */
    public function testReverseViaMatchesOuterRowsByScopeFieldValue(): void
    {
        $objectService = $this->objectService(
            [
                'learnerProfile' => [
                    // Verified: the subject guardians this learner.
                    ['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a'],
                    // Foreign guardian — dropped, so learner-x never enters the set.
                    ['guardianRefs' => ['guardian-9'], 'learnerRef' => 'learner-x'],
                    ['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-b'],
                ],
                'gradeEntry'     => [
                    ['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Math'],
                    ['id' => 'g-2', 'learnerRef' => 'learner-x', 'title' => 'Foreign learner'],
                    ['id' => 'g-3', 'learnerRef' => 'learner-b', 'title' => 'Science'],
                    ['id' => 'g-4', 'learnerRef' => 'learner-z', 'title' => 'Unrelated'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'scholiq',
            schema: 'gradeEntry',
            scopeField: 'learnerRef',
            subjectRef: 'guardian-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'scholiq',
            via: [
                'register'    => 'scholiq',
                'schema'      => 'learnerProfile',
                'scopeField'  => 'guardianRefs',
                'targetField' => 'learnerRef',
                'match'       => 'scopeField',
            ],
            audience: 'parent'
        );

        // Kept because learnerRef ∈ {learner-a, learner-b}; g-2/g-4 dropped.
        $this->assertSame(['Math', 'Science'], array_column($rows, 'title'));
        // The join pre-pass queried the join schema, the outer read the target.
        $this->assertSame('learnerProfile', $objectService->calls[0]['schema']);
        $this->assertSame('gradeEntry', $objectService->calls[1]['schema']);

    }//end testReverseViaMatchesOuterRowsByScopeFieldValue()

    /**
     * A multi-value scopeField matches when ANY of its elements is in the
     * verified set (strict, element-wise) — and stays excluded when none is.
     */
    public function testReverseViaArrayScopeFieldMatchesOnAnyElement(): void
    {
        $objectService = $this->objectService(
            [
                'learnerProfile' => [
                    ['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-b'],
                ],
                'gradeEntry'     => [
                    // learner-b is one of several refs on this row → matched.
                    ['id' => 'g-1', 'learnerRefs' => ['learner-q', 'learner-b'], 'title' => 'Shared project'],
                    // None of these refs is in the verified set → dropped.
                    ['id' => 'g-2', 'learnerRefs' => ['learner-q', 'learner-r'], 'title' => 'Other group'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'scholiq',
            schema: 'gradeEntry',
            scopeField: 'learnerRefs',
            subjectRef: 'guardian-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'scholiq',
            via: [
                'register'    => 'scholiq',
                'schema'      => 'learnerProfile',
                'scopeField'  => 'guardianRefs',
                'targetField' => 'learnerRef',
                'match'       => 'scopeField',
            ],
            audience: 'parent'
        );

        $this->assertSame(['Shared project'], array_column($rows, 'title'));

    }//end testReverseViaArrayScopeFieldMatchesOnAnyElement()

    /**
     * Security invariant: reverse match NEVER widens. An empty verified target
     * set yields zero rows and the outer read is skipped entirely — the
     * absence of matches can only ever exclude, never fall through to "all
     * rows".
     */
    public function testReverseViaEmptyVerifiedTargetSetYieldsZeroRows(): void
    {
        $objectService = $this->objectService(
            [
                // No profile links this guardian → empty target set.
                'learnerProfile' => [
                    ['guardianRefs' => ['guardian-9'], 'learnerRef' => 'learner-x'],
                ],
                'gradeEntry'     => [
                    ['id' => 'g-1', 'learnerRef' => 'learner-x', 'title' => 'Must never surface'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'scholiq',
            schema: 'gradeEntry',
            scopeField: 'learnerRef',
            subjectRef: 'guardian-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'scholiq',
            via: [
                'register'    => 'scholiq',
                'schema'      => 'learnerProfile',
                'scopeField'  => 'guardianRefs',
                'targetField' => 'learnerRef',
                'match'       => 'scopeField',
            ],
            audience: 'parent'
        );

        $this->assertSame([], $rows);
        // Only the join pre-pass ran; the outer read was never issued.
        $this->assertCount(1, $objectService->calls);
        $this->assertSame('learnerProfile', $objectService->calls[0]['schema']);

    }//end testReverseViaEmptyVerifiedTargetSetYieldsZeroRows()

    /**
     * Security invariant: an outer row whose scopeField is absent or null is
     * excluded — a missing key normalises to no candidates, never a wildcard.
     */
    public function testReverseViaExcludesRowsWithAbsentOrNullScopeField(): void
    {
        $objectService = $this->objectService(
            [
                'learnerProfile' => [
                    ['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a'],
                ],
                'gradeEntry'     => [
                    ['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Mine'],
                    // scopeField entirely absent — excluded.
                    ['id' => 'g-2', 'title' => 'No scope key'],
                    // scopeField present but null — excluded.
                    ['id' => 'g-3', 'learnerRef' => null, 'title' => 'Null scope key'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'scholiq',
            schema: 'gradeEntry',
            scopeField: 'learnerRef',
            subjectRef: 'guardian-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'scholiq',
            via: [
                'register'    => 'scholiq',
                'schema'      => 'learnerProfile',
                'scopeField'  => 'guardianRefs',
                'targetField' => 'learnerRef',
                'match'       => 'scopeField',
            ],
            audience: 'parent'
        );

        $this->assertSame(['Mine'], array_column($rows, 'title'));

    }//end testReverseViaExcludesRowsWithAbsentOrNullScopeField()

    /**
     * Security invariant: a `match` value that is neither 'id' nor
     * 'scopeField' fails the whole via closed (invalid via → zero rows + a
     * logged warning, no OR query) — exactly like a structurally invalid via.
     */
    public function testReverseViaMalformedMatchFailsClosed(): void
    {
        foreach (['reverse', 'ID', 'scopefield', '', 42, true] as $badMatch) {
            $objectService = $this->objectService(
                [
                    'learnerProfile' => [['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a']],
                    'gradeEntry'     => [['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Never']],
                ]
            );
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($this->once())->method('warning');

            $reader = new PortalObjectReader($this->container($objectService), $logger);
            $rows   = $reader->readCollection(
                register: 'scholiq',
                schema: 'gradeEntry',
                scopeField: 'learnerRef',
                subjectRef: 'guardian-1',
                organisation: '',
                limit: 200,
                scopeClaim: '',
                contributingApp: 'scholiq',
                via: [
                    'register'    => 'scholiq',
                    'schema'      => 'learnerProfile',
                    'scopeField'  => 'guardianRefs',
                    'targetField' => 'learnerRef',
                    'match'       => $badMatch,
                ],
                audience: 'parent'
            );

            $this->assertSame([], $rows, 'match value must fail closed');
            $this->assertCount(0, $objectService->calls);
        }//end foreach

    }//end testReverseViaMalformedMatchFailsClosed()

    /**
     * Security invariant: the per-row tenant check on the OUTER rows is
     * preserved in reverse mode. A grade for a verified learner but belonging
     * to a different tenant is still dropped.
     */
    public function testReverseViaTenantMismatchOnOuterRowExcluded(): void
    {
        $objectService = $this->objectService(
            [
                'learnerProfile' => [
                    ['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a', 'organisation' => 'org-1'],
                ],
                'gradeEntry'     => [
                    ['id' => 'g-1', 'learnerRef' => 'learner-a', 'organisation' => 'org-1', 'title' => 'Mine'],
                    // Same verified learner, but a foreign tenant — dropped.
                    ['id' => 'g-2', 'learnerRef' => 'learner-a', 'organisation' => 'org-2', 'title' => 'Other tenant'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'scholiq',
            schema: 'gradeEntry',
            scopeField: 'learnerRef',
            subjectRef: 'guardian-1',
            organisation: 'org-1',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'scholiq',
            via: [
                'register'    => 'scholiq',
                'schema'      => 'learnerProfile',
                'scopeField'  => 'guardianRefs',
                'targetField' => 'learnerRef',
                'match'       => 'scopeField',
            ],
            audience: 'parent'
        );

        $this->assertSame(['Mine'], array_column($rows, 'title'));

    }//end testReverseViaTenantMismatchOnOuterRowExcluded()

    /**
     * Regression pin: an EXPLICIT `match: 'id'` is byte-for-byte the forward
     * behaviour (and identical to omitting `match`) — outer rows matched by
     * their OWN id/uuid, the existing zaak/rol scenario unchanged.
     */
    public function testForwardViaWithExplicitIdMatchIsUnchanged(): void
    {
        $rows = [];
        foreach ([['match' => 'id'], []] as $matchKey) {
            $objectService = $this->objectService(
                [
                    'rol'  => [
                        ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
                        ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-2'], 'zaak' => 'z-2'],
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
            $rows[] = $reader->readCollection(
                register: 'zaken',
                schema: 'zaak',
                scopeField: 'irrelevantForVia',
                subjectRef: 'bsn-1',
                organisation: '',
                limit: 200,
                scopeClaim: '',
                contributingApp: 'zaakafhandelapp',
                via: array_merge(
                    [
                        'register'    => 'zaken',
                        'schema'      => 'rol',
                        'scopeField'  => 'betrokkeneIdentificatie.inpBsn',
                        'targetField' => 'zaak',
                    ],
                    $matchKey
                ),
                audience: 'citizen'
            );
        }//end foreach

        // Explicit match:'id' and omitted match produce the identical forward
        // result — matched by the outer row's OWN id/uuid.
        $this->assertSame(['Mine', 'Envelope id'], array_column($rows[0], 'title'));
        $this->assertSame($rows[0], $rows[1]);

    }//end testForwardViaWithExplicitIdMatchIsUnchanged()

    /**
     * Field projection still runs AFTER reverse filtering: reverse-joined rows
     * are projected to the declared whitelist + identifier, exactly like the
     * forward via path.
     */
    public function testReverseViaProjectionAppliedToReverseJoinedRows(): void
    {
        $objectService = $this->objectService(
            [
                'learnerProfile' => [
                    ['guardianRefs' => ['guardian-1'], 'learnerRef' => 'learner-a'],
                ],
                'gradeEntry'     => [
                    ['id' => 'g-1', 'learnerRef' => 'learner-a', 'title' => 'Math', 'grade' => '8', 'teacherNotes' => 'staff only'],
                    ['id' => 'g-9', 'learnerRef' => 'learner-z', 'title' => 'Unrelated', 'grade' => '4'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'scholiq',
            schema: 'gradeEntry',
            scopeField: 'learnerRef',
            subjectRef: 'guardian-1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'scholiq',
            via: [
                'register'    => 'scholiq',
                'schema'      => 'learnerProfile',
                'scopeField'  => 'guardianRefs',
                'targetField' => 'learnerRef',
                'match'       => 'scopeField',
            ],
            audience: 'parent',
            fields: ['title', 'grade']
        );

        // Reverse membership decides WHICH rows return; projection then decides
        // what each shows — teacherNotes and the scopeField are absent.
        $this->assertSame([['title' => 'Math', 'grade' => '8', 'id' => 'g-1']], $rows);

    }//end testReverseViaProjectionAppliedToReverseJoinedRows()

    public function testProjectionReturnsOnlyDeclaredFieldsPlusIdentifierAfterVerification(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    // A staff-only field that must never reach the portal, and
                    // a foreign row that verification must still drop even
                    // though fields are declared (projection ≠ authorisation).
                    ['id' => 'b-1', 'uuid' => 'u-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine', 'status' => 'open', 'internalNotes' => 'staff only'],
                    ['id' => 'b-2', 'uuid' => 'u-2', 'subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Foreign', 'status' => 'open', 'internalNotes' => 'staff only'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'pipelinq',
            schema: 'booking',
            scopeField: 'subjectRef',
            subjectRef: 's1',
            organisation: 'org-1',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'pipelinq',
            via: null,
            audience: 'client',
            fields: ['title', 'status']
        );

        // Exactly the declared fields + the identifiers — nothing else. The
        // scopeField value (subjectRef) is NOT auto-included.
        $this->assertSame(
            [
                [
                    'title'  => 'Mine',
                    'status' => 'open',
                    'id'     => 'b-1',
                    'uuid'   => 'u-1',
                ],
            ],
            $rows
        );

    }//end testProjectionReturnsOnlyDeclaredFieldsPlusIdentifierAfterVerification()

    public function testProjectionKeepsReducedEnvelopeIdentifierAndDropsUnknownDeclaredFields(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    // Identifier only inside the @self envelope, which also
                    // carries metadata that must not leak through projection.
                    ['@self' => ['id' => 'b-1', 'uuid' => 'u-1', 'organisation' => 'org-1', 'owner' => 'admin'], 'subjectRef' => 's1', 'title' => 'Mine'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection(
            register: 'pipelinq',
            schema: 'booking',
            scopeField: 'subjectRef',
            subjectRef: 's1',
            organisation: '',
            limit: 200,
            scopeClaim: '',
            contributingApp: 'pipelinq',
            via: null,
            audience: 'client',
            fields: ['title', 'notAProperty']
        );

        // Unknown declared fields simply project to absent (no error), and
        // the envelope reduces to its identifier members only.
        $this->assertSame(
            [
                [
                    'title' => 'Mine',
                    '@self' => [
                        'id'   => 'b-1',
                        'uuid' => 'u-1',
                    ],
                ],
            ],
            $rows
        );

    }//end testProjectionKeepsReducedEnvelopeIdentifierAndDropsUnknownDeclaredFields()

    public function testNoFieldsDeclarationKeepsFullRows(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    ['id' => 'b-1', 'subjectRef' => 's1', 'title' => 'Mine', 'internalNotes' => 'still here without a declaration'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $rows   = $reader->readCollection('pipelinq', 'booking', 'subjectRef', 's1');

        // Backward compatible: absent fields = the full verified row.
        $this->assertSame('still here without a declaration', $rows[0]['internalNotes']);
        $this->assertSame('s1', $rows[0]['subjectRef']);

    }//end testNoFieldsDeclarationKeepsFullRows()

    public function testMalformedFieldsDeclarationFailsClosedToIdentifiersOnly(): void
    {
        foreach (['title,status', ['', 123, null]] as $malformed) {
            $objectService = $this->objectService(
                [
                    'booking' => [
                        ['id' => 'b-1', 'uuid' => 'u-1', 'subjectRef' => 's1', 'title' => 'Mine', 'internalNotes' => 'staff only'],
                    ],
                ]
            );

            $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
            $rows   = $reader->readCollection(
                register: 'pipelinq',
                schema: 'booking',
                scopeField: 'subjectRef',
                subjectRef: 's1',
                organisation: '',
                limit: 200,
                scopeClaim: '',
                contributingApp: 'pipelinq',
                via: null,
                audience: 'client',
                fields: $malformed
            );

            // A declared-but-malformed projection intent never fails open to
            // the full row — identifiers only.
            $this->assertSame([['id' => 'b-1', 'uuid' => 'u-1']], $rows);
        }

    }//end testMalformedFieldsDeclarationFailsClosedToIdentifiersOnly()

    public function testProjectionAppliesOnTheViaPathAfterTargetVerification(): void
    {
        $objectService = $this->objectService(
            [
                'rol'  => [
                    ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
                ],
                'zaak' => [
                    ['uuid' => 'z-1', 'title' => 'Mine', 'status' => 'open', 'behandelaarNotities' => 'staff only'],
                    ['uuid' => 'z-9', 'title' => 'Unreferenced', 'status' => 'open'],
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
            audience: 'citizen',
            fields: ['title']
        );

        // Join membership still decides WHICH rows return; projection then
        // decides what each verified row SHOWS.
        $this->assertSame([['title' => 'Mine', 'uuid' => 'z-1']], $rows);

    }//end testProjectionAppliesOnTheViaPathAfterTargetVerification()

    public function testProjectRowSingleObjectDetailSemantics(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('not needed'));
        $reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class));

        $row = [
            'id'         => 'd-1',
            'subjectRef' => 's1',
            'title'      => 'Mine',
            'notes'      => 'staff only',
        ];

        // The public single-row primitive a future detail read must call:
        // whitelist + identifier, null passes the row through whole.
        $this->assertSame(['title' => 'Mine', 'id' => 'd-1'], $reader->projectRow($row, ['title']));
        $this->assertSame($row, $reader->projectRow($row, null));
        // Declaring '@self' explicitly keeps the full envelope (whitelist
        // escape hatch documented in the design).
        $withSelf = ['@self' => ['id' => 'd-1', 'owner' => 'admin'], 'title' => 'Mine'];
        $this->assertSame($withSelf, $reader->projectRow($withSelf, ['@self', 'title']));

    }//end testProjectRowSingleObjectDetailSemantics()

    public function testReadObjectReturnsNullWhenOpenRegisterUnavailable(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willThrowException(new RuntimeException('OR not installed'));

        $reader = new PortalObjectReader($container, $this->createMock(LoggerInterface::class));
        $this->assertNull($reader->readObject('portaliq', 'exampleDocument', 'subjectRef', 's1', 'd-1'));

    }//end testReadObjectReturnsNullWhenOpenRegisterUnavailable()

    public function testReadObjectReturnsTheSubjectsOwnProjectedObject(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    ['id' => 'b-1', 'uuid' => 'u-1', 'subjectRef' => 's1', 'organisation' => 'org-1', 'title' => 'Mine', 'status' => 'open', 'internalNotes' => 'staff only'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $object = $reader->readObject(
            register: 'pipelinq',
            schema: 'booking',
            scopeField: 'subjectRef',
            subjectRef: 's1',
            id: 'b-1',
            organisation: 'org-1',
            fields: ['title', 'status']
        );

        // Projected to the whitelist + identifiers; the scopeField value and
        // staff-only notes never leave.
        $this->assertSame(['title' => 'Mine', 'status' => 'open', 'id' => 'b-1', 'uuid' => 'u-1'], $object);
        // The fetch is scoped register/schema, id-filtered, RBAC-bypassed.
        $this->assertSame('booking', $objectService->calls[0]['schema']);
        $this->assertSame('b-1', $objectService->calls[0]['config']['filters']['id']);
        $this->assertFalse($objectService->calls[0]['rbac']);

    }//end testReadObjectReturnsTheSubjectsOwnProjectedObject()

    /**
     * ISOLATION: an object owned by a DIFFERENT subject is never returned,
     * even though OpenRegister returned it for the requested id — the per-row
     * ownership check drops it → null (→ 404, no oracle).
     */
    public function testReadObjectReturnsNullForAForeignOwnedObject(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    ['id' => 'b-2', 'uuid' => 'u-2', 'subjectRef' => 's2', 'organisation' => 'org-1', 'title' => 'Not mine'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $object = $reader->readObject(
            register: 'pipelinq',
            schema: 'booking',
            scopeField: 'subjectRef',
            subjectRef: 's1',
            id: 'b-2',
            organisation: 'org-1'
        );

        $this->assertNull($object);

    }//end testReadObjectReturnsNullForAForeignOwnedObject()

    /**
     * ISOLATION: a different tenant's object with the subject's OWN scope value
     * is still dropped by the per-row tenant check.
     */
    public function testReadObjectReturnsNullForAForeignTenant(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    ['id' => 'b-3', 'subjectRef' => 's1', 'organisation' => 'org-2', 'title' => 'Other tenant'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $object = $reader->readObject(
            register: 'pipelinq',
            schema: 'booking',
            scopeField: 'subjectRef',
            subjectRef: 's1',
            id: 'b-3',
            organisation: 'org-1'
        );

        $this->assertNull($object);

    }//end testReadObjectReturnsNullForAForeignTenant()

    public function testReadObjectReturnsNullForANonExistentId(): void
    {
        $objectService = $this->objectService(
            [
                'booking' => [
                    ['id' => 'b-1', 'subjectRef' => 's1', 'title' => 'Mine'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        // Requested id is not among the returned rows.
        $this->assertNull($reader->readObject('pipelinq', 'booking', 'subjectRef', 's1', 'does-not-exist'));
        // And an empty id fails closed without any query.
        $this->assertNull($reader->readObject('pipelinq', 'booking', 'subjectRef', 's1', ''));
        $this->assertCount(1, $objectService->calls);

    }//end testReadObjectReturnsNullForANonExistentId()

    /**
     * ISOLATION: a claim-scoped single read resolves the claim server-side and
     * only returns the object matching the RESOLVED claim value; an absent
     * claim fails closed to null WITHOUT the object fetch.
     */
    public function testReadObjectResolvesTheScopeClaimServerSide(): void
    {
        $objectService = $this->objectService(
            [
                'portalAccount' => [
                    ['subjectRef' => 's1', 'claims' => ['pipelinq' => ['linkedContactId' => 'uuid-c1']]],
                ],
                'crmDeal'       => [
                    ['id' => 'd-1', 'contact' => 'uuid-c1', 'title' => 'Mine'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $object = $reader->readObject(
            register: 'pipelinq',
            schema: 'crmDeal',
            scopeField: 'contact',
            subjectRef: 's1',
            id: 'd-1',
            organisation: '',
            scopeClaim: 'linkedContactId',
            contributingApp: 'pipelinq',
            audience: 'supplier'
        );

        $this->assertSame('Mine', $object['title']);

    }//end testReadObjectResolvesTheScopeClaimServerSide()

    public function testReadObjectReturnsNullWhenScopeClaimAbsentWithoutFetch(): void
    {
        $objectService = $this->objectService(
            [
                'portalAccount' => [
                    ['subjectRef' => 's1', 'claims' => []],
                ],
                'crmDeal'       => [
                    ['id' => 'd-1', 'contact' => 'uuid-c1', 'title' => 'Must never surface'],
                ],
            ]
        );

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));
        $object = $reader->readObject(
            register: 'pipelinq',
            schema: 'crmDeal',
            scopeField: 'contact',
            subjectRef: 's1',
            id: 'd-1',
            organisation: '',
            scopeClaim: 'linkedContactId',
            contributingApp: 'pipelinq',
            audience: 'supplier'
        );

        $this->assertNull($object);
        // Only the portalAccount lookup ran — the object was NEVER fetched.
        $this->assertCount(1, $objectService->calls);
        $this->assertSame('portalAccount', $objectService->calls[0]['schema']);

    }//end testReadObjectReturnsNullWhenScopeClaimAbsentWithoutFetch()

    /**
     * A via-collection single read passes the object through the SAME one-hop
     * join membership as the list path: the subject's linked case is returned,
     * a case the subject's join rows do not reference is null.
     */
    public function testReadObjectViaCollectionVerifiesJoinMembership(): void
    {
        $objectService = $this->objectService(
            [
                'rol'  => [
                    ['betrokkeneIdentificatie' => ['inpBsn' => 'bsn-1'], 'zaak' => 'z-1'],
                ],
                'zaak' => [
                    ['uuid' => 'z-1', 'title' => 'Mine'],
                    ['uuid' => 'z-9', 'title' => 'Unreferenced'],
                ],
            ]
        );
        $via = [
            'register'    => 'zaken',
            'schema'      => 'rol',
            'scopeField'  => 'betrokkeneIdentificatie.inpBsn',
            'targetField' => 'zaak',
        ];

        $reader = new PortalObjectReader($this->container($objectService), $this->createMock(LoggerInterface::class));

        $mine = $reader->readObject(
            register: 'zaken',
            schema: 'zaak',
            scopeField: 'irrelevantForVia',
            subjectRef: 'bsn-1',
            id: 'z-1',
            organisation: '',
            via: $via,
            audience: 'citizen'
        );
        $this->assertSame('Mine', $mine['title']);

        // A case the subject's join rows do NOT reference → null (not visible).
        $foreign = $reader->readObject(
            register: 'zaken',
            schema: 'zaak',
            scopeField: 'irrelevantForVia',
            subjectRef: 'bsn-1',
            id: 'z-9',
            organisation: '',
            via: $via,
            audience: 'citizen'
        );
        $this->assertNull($foreign);

    }//end testReadObjectViaCollectionVerifiesJoinMembership()

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
