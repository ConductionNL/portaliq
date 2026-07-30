<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Contract v2 controller tests: the fail-closed minTrust re-checks on the
 * read and create paths (defense in depth on top of the trust-filtered
 * aggregate — 403 BEFORE any OpenRegister call), the v2 scope parameters
 * (scopeClaim / contributing app / via / audience) reaching the reader, the
 * `claims` whitelist guard, and the A6 endpoint-action forward: manifest
 * authorisation, SSRF guard, X-Portal-Subject assertion (never the client's
 * Authorization), response relay, and the 502 transport posture. The
 * field-projection cases prove the declared `fields` whitelist reaches the
 * reader untouched (null = no projection) — for plain AND inbox collections.
 *
 * @spec openspec/changes/contract-v2/tasks.md#T3
 * @spec openspec/changes/contract-v2/tasks.md#T5
 * @spec openspec/changes/contract-v2/tasks.md#T8
 * @spec openspec/changes/field-projection/tasks.md#T2
 */
class ContributionControllerTest extends TestCase
{

    private const SUBJECT = [
        'subjectRef'   => 's1',
        'audience'     => 'supplier',
        'organisation' => 'org-1',
        'trust'        => 'low',
        'roles'        => [],
        'jti'          => 'session-jti-1',
    ];

    /**
     * portal-page-provisioning: with NO anonymous entries anywhere, a
     * no-bearer index() call serves the (empty) anonymous aggregate — 200,
     * not 401. In production PortalAuthMiddleware would already have thrown
     * before this method ran; this proves the controller's OWN behaviour in
     * isolation.
     */
    public function testIndexServesEmptyAnonymousAggregateWhenSubjectResolvesNullAndNoAnonymousEntries(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $response   = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([], $response->getData()['contributions']);
        $this->assertSame(0, $response->getData()['unreadCount']);

    }//end testIndexServesEmptyAnonymousAggregateWhenSubjectResolvesNullAndNoAnonymousEntries()

    /**
     * portal-page-provisioning (spec: "An anonymous visitor can read the page
     * layout before submitting"): a no-bearer index() call serves
     * `aggregateAnonymous()`'s manifest — the anonymous-only slice — instead
     * of a page-shaped 401.
     */
    public function testIndexServesAnonymousAggregateWhenSubjectResolvesNull(): void
    {
        $anonymousAggregate = [
            'contributions' => [
                ['app' => 'portaliq', 'label' => 'Meldingen', 'collections' => [], 'actions' => [['id' => 'openIntake', 'type' => 'create', 'anonymous' => true]]],
            ],
        ];

        $controller = $this->controller(aggregate: $this->aggregate(), subject: null, anonymousAggregate: $anonymousAggregate);
        $response   = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($anonymousAggregate['contributions'], $response->getData()['contributions']);
        $this->assertSame(0, $response->getData()['unreadCount']);

    }//end testIndexServesAnonymousAggregateWhenSubjectResolvesNull()

    public function testIndexReturnsTheRegistrysAggregateForAnAuthenticatedSubject(): void
    {
        $aggregate  = $this->aggregate(collections: [['register' => 'r1', 'schema' => 'a']]);
        $controller = $this->controller(aggregate: $aggregate);
        $response   = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        // The aggregate is returned unchanged PLUS the unread count
        // (portal-inbox-v2 T04) — the default inbox reader stub yields 0.
        $this->assertSame(($aggregate + ['unreadCount' => 0]), $response->getData());

    }//end testIndexReturnsTheRegistrysAggregateForAnAuthenticatedSubject()

    /**
     * The contributions response carries the subject's own unread count,
     * computed by PortalInboxReader over the SAME aggregate (portal-inbox-v2 T04).
     */
    public function testIndexIncludesTheSubjectsUnreadCount(): void
    {
        $aggregate = $this->aggregate(collections: [['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage']]);

        $inboxReader = $this->createMock(PortalInboxReader::class);
        $inboxReader->expects($this->once())->method('unreadCount')
            ->with(self::SUBJECT, $aggregate)
            ->willReturn(3);

        $controller = $this->controller(aggregate: $aggregate, inboxReader: $inboxReader);
        $response   = $controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(3, $response->getData()['unreadCount']);

    }//end testIndexIncludesTheSubjectsUnreadCount()

    public function testCollectionOutsideSubjectsManifestIs403IdorGuard(): void
    {
        // The IDOR guard: (register, schema) simply never appears in the
        // subject's own aggregated contributions — distinct from the
        // minTrust-based 403 covered below.
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'r1', 'schema' => 'a'],
            ]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->expects($this->never())->method('readCollection');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader);
        $response   = $controller->collection('r-not-granted', 'schema-not-granted');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCollectionOutsideSubjectsManifestIs403IdorGuard()

    public function testCollectionUnauthenticatedIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->collection('r1', 'a')->getStatus());

    }//end testCollectionUnauthenticatedIs401()

    public function testCreateWithoutAMatchingActionIs403(): void
    {
        // No declared `type: create` action for (register, schema) at all —
        // distinct from the minTrust-based 403 covered below.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'other-register', 'schema' => 'other-schema', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('createObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCreateWithoutAMatchingActionIs403()

    /**
     * portal-page-provisioning: a no-bearer create() call with NO anonymous
     * `type: create` action declared for this exact (register, schema) is
     * 403 forbidden — the anonymous path never becomes an open write.
     */
    public function testCreateUnauthenticatedWithNoAnonymousActionIs403(): void
    {
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('createAnonymousObject');

        $controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCreateUnauthenticatedWithNoAnonymousActionIs403()

    /**
     * portal-page-provisioning (spec: "An anonymous citizen submits a public
     * intake form"): a no-bearer create() call matching an `anonymous: true`,
     * `type: create` action for the EXACT (register, schema) succeeds — only
     * the whitelisted fields are written, through
     * PortalObjectWriter::createAnonymousObject() (NO subject/organisation
     * stamp), never `createObject()`.
     */
    public function testAnonymousCreateSucceedsForAnAnonymousAction(): void
    {
        $anonymousAggregate = [
            'contributions' => [
                [
                    'app'     => 'openbuild',
                    'actions' => [
                        ['id' => 'openIntake', 'type' => 'create', 'register' => 'openbuild', 'schema' => 'melding', 'fields' => ['title'], 'anonymous' => true],
                    ],
                ],
            ],
        ];

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->once())->method('createAnonymousObject')
            ->with('openbuild', 'melding', ['title' => 'X'])
            ->willReturn(['id' => 'new-object', 'title' => 'X']);
        // The authenticated create() path must NEVER be used on the
        // anonymous branch — no ownership to stamp.
        $writer->expects($this->never())->method('createObject');

        $controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer, anonymousAggregate: $anonymousAggregate);
        $response   = $controller->create('openbuild', 'melding');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['id' => 'new-object', 'title' => 'X'], $response->getData()['object']);

    }//end testAnonymousCreateSucceedsForAnAnonymousAction()

    /**
     * A `type: create` action for the SAME (register, schema) that does NOT
     * declare `anonymous: true` is not matched by the anonymous path — the
     * write is rejected 403, no write attempted. (The bearer-authenticated
     * path for the SAME action is covered by the existing
     * testCreatesAnObjectOwnedBySubject-style tests below; this proves the
     * anonymous branch specifically requires the flag, not just any create
     * action existing for the target.)
     */
    public function testAnonymousCreateRejectsANonAnonymousActionForTheSameTarget(): void
    {
        $anonymousAggregate = [
            'contributions' => [
                [
                    'app'     => 'openbuild',
                    'actions' => [
                        // Present in the AUTHENTICATED aggregate but this
                        // fixture simulates it NOT surviving into
                        // aggregateAnonymous() because it never declared
                        // anonymous: true — the registry already dropped it.
                    ],
                ],
            ],
        ];

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('createAnonymousObject');

        $controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer, anonymousAggregate: $anonymousAggregate);
        $response   = $controller->create('openbuild', 'melding');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testAnonymousCreateRejectsANonAnonymousActionForTheSameTarget()

    /**
     * `defaults` are stamped server-side over the whitelisted payload on the
     * anonymous path too — identical discipline to the authenticated path —
     * so a schema's own required-but-not-client-editable field (e.g. a
     * placeholder ownership marker) can be satisfied without a real subject.
     */
    public function testAnonymousCreateAppliesDefaultsOverTheWhitelist(): void
    {
        $anonymousAggregate = [
            'contributions' => [
                [
                    'app'     => 'portaliq',
                    'actions' => [
                        [
                            'id'         => 'publicIntake',
                            'type'       => 'create',
                            'register'   => 'portaliq',
                            'schema'     => 'exampleDocument',
                            'fields'     => ['title'],
                            'defaults'   => ['subjectRef' => 'anonymous'],
                            'anonymous'  => true,
                        ],
                    ],
                ],
            ],
        ];

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->once())->method('createAnonymousObject')
            ->with('portaliq', 'exampleDocument', ['title' => 'X', 'subjectRef' => 'anonymous'])
            ->willReturn(['id' => 'new-object']);

        $controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer, anonymousAggregate: $anonymousAggregate);
        $response   = $controller->create('portaliq', 'exampleDocument');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testAnonymousCreateAppliesDefaultsOverTheWhitelist()

    /**
     * A failed anonymous OpenRegister write is relayed as 502, exactly like
     * the authenticated path.
     */
    public function testAnonymousCreateWriteFailureIs502(): void
    {
        $anonymousAggregate = [
            'contributions' => [
                ['app' => 'openbuild', 'actions' => [['id' => 'x', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'anonymous' => true]]],
            ],
        ];

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createAnonymousObject')->willReturn(null);

        $controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer, anonymousAggregate: $anonymousAggregate);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

    }//end testAnonymousCreateWriteFailureIs502()

    public function testCollectionBelowTrustThresholdIs403BeforeAnyRead(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'r1', 'schema' => 'a', 'minTrust' => 'substantial'],
            ]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->expects($this->never())->method('readCollection');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader);
        $response   = $controller->collection('r1', 'a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCollectionBelowTrustThresholdIs403BeforeAnyRead()

    public function testCreateBelowTrustThresholdIs403BeforeAnyWrite(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'minTrust' => 'high'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('createObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testCreateBelowTrustThresholdIs403BeforeAnyWrite()

    public function testCollectionPassesV2ScopeParametersToReader(): void
    {
        $via       = [
            'register'    => 'zaken',
            'schema'      => 'rol',
            'scopeField'  => 'betrokkeneIdentificatie.inpBsn',
            'targetField' => 'zaak',
        ];
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'own', 'scopeClaim' => 'linkedContactId', 'via' => $via, 'fields' => ['title', 'status']],
            ]
        );

        $received   = [];
        $reader     = $this->readerCapturing($received);
        $controller = $this->controller(aggregate: $aggregate, reader: $reader);
        $response   = $controller->collection('zaken', 'zaak');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        // Named arguments arrive keyed on the mocked signature.
        $this->assertSame('zaken', $received['register']);
        $this->assertSame('zaak', $received['schema']);
        $this->assertSame('own', $received['scopeField']);
        $this->assertSame('s1', $received['subjectRef']);
        $this->assertSame('org-1', $received['organisation']);
        $this->assertSame('linkedContactId', $received['scopeClaim']);
        $this->assertSame('portaliq', $received['contributingApp']);
        $this->assertSame($via, $received['via']);
        $this->assertSame('supplier', $received['audience']);
        // The declared projection whitelist travels to the reader untouched.
        $this->assertSame(['title', 'status'], $received['fields']);

    }//end testCollectionPassesV2ScopeParametersToReader()

    public function testInboxCollectionFieldsReachTheReaderAndAbsentFieldsStayNull(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'subjectRef', 'fields' => ['subject', 'read']],
                ['register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef'],
            ]
        );

        $received   = [];
        $reader     = $this->readerCapturing($received);
        $controller = $this->controller(aggregate: $aggregate, reader: $reader);

        // A kind:'inbox' collection may declare fields like any other.
        $this->assertSame(Http::STATUS_OK, $controller->collection('portaliq', 'portalMessage')->getStatus());
        $this->assertSame(['subject', 'read'], $received['fields']);

        // No declaration → null, which the reader treats as "full rows".
        $this->assertSame(Http::STATUS_OK, $controller->collection('portaliq', 'exampleDocument')->getStatus());
        $this->assertNull($received['fields']);

    }//end testInboxCollectionFieldsReachTheReaderAndAbsentFieldsStayNull()

    public function testCreateNeverPassesClaimsToTheWriter(): void
    {
        // Even a contribution that (mistakenly) whitelists `claims` must not
        // let a client-supplied claim map reach the OpenRegister write.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title', 'claims']],
            ]
        );

        $saved  = null;
        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$saved) {
                $saved = $data;
                return ['id' => 'new'];
            }
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['title' => 'X'], $saved);
        $this->assertArrayNotHasKey('claims', $saved);

    }//end testCreateNeverPassesClaimsToTheWriter()

    public function testCreateRecordsACreateAuditEntryWithTheNewId(): void
    {
        // portal-session-hardening-v2 T09: a successful create() records a
        // `create` audit entry carrying the NEWLY created object's id.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturn(['id' => 'new-object-id']);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->expects($this->once())->method('record')->with(
            'create',
            's1',
            'org-1',
            'r1',
            'a',
            'new-object-id',
            'session-jti-1'
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, auditor: $auditor);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testCreateRecordsACreateAuditEntryWithTheNewId()

    /**
     * WMEBV (wmebv-submission-receipts): a successful create fires
     * SubmissionReceiptService::record() with the subject/tenant scope, the
     * contributing app id, the action id, and the EXACT whitelisted field map
     * just persisted (never the raw request body).
     */
    public function testSuccessfulCreateTriggersReceiptRecordWithWhitelistedMap(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturn(['id' => 'new']);

        $received       = [];
        $receiptService = $this->createMock(SubmissionReceiptService::class);
        $receiptService->expects($this->once())->method('record')->willReturnCallback(
            function (string $subjectRef, string $organisation, string $appId, string $actionId, array $whitelistedData) use (&$received) {
                $received = [
                    'subjectRef'      => $subjectRef,
                    'organisation'    => $organisation,
                    'appId'           => $appId,
                    'actionId'        => $actionId,
                    'whitelistedData' => $whitelistedData,
                ];
            }
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, receiptService: $receiptService);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('s1', $received['subjectRef']);
        $this->assertSame('org-1', $received['organisation']);
        $this->assertSame('portaliq', $received['appId']);
        $this->assertSame('c1', $received['actionId']);
        $this->assertSame(['title' => 'X'], $received['whitelistedData']);

    }//end testSuccessfulCreateTriggersReceiptRecordWithWhitelistedMap()

    /**
     * WMEBV: a create that never reaches the domain write (403 IDOR, 403
     * trust) never fires the receipt — no receipt/log for a submission the
     * subject was never entitled to make.
     */
    public function testForbiddenCreateNeverTriggersReceiptRecord(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'other-register', 'schema' => 'other-schema', 'fields' => ['title']],
            ]
        );

        $receiptService = $this->createMock(SubmissionReceiptService::class);
        $receiptService->expects($this->never())->method('record');

        $controller = $this->controller(aggregate: $aggregate, receiptService: $receiptService);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testForbiddenCreateNeverTriggersReceiptRecord()

    /**
     * A create whose domain write fails (writer returns null → 502) never
     * fires either follow-on: not `AuditTrailService::record()`
     * (portal-session-hardening-v2 T09) and not the WMEBV receipt — the
     * domain write is the authority; nothing was actually persisted, so
     * nothing should be audited, receipted, or logged.
     */
    public function testFailedDomainWriteNeverTriggersAuditOrReceiptRecord(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('createObject')->willReturn(null);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->expects($this->never())->method('record');

        $receiptService = $this->createMock(SubmissionReceiptService::class);
        $receiptService->expects($this->never())->method('record');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, auditor: $auditor, receiptService: $receiptService);
        $response   = $controller->create('r1', 'a');

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

    }//end testFailedDomainWriteNeverTriggersAuditOrReceiptRecord()

    public function testActionOutsideManifestIs403WithoutOutboundCall(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'known', 'endpoint' => '/apps/portaliq/api/health', 'method' => 'GET'],
            ]
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->expects($this->never())->method('newClient');

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService);

        // Unknown action id.
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->action('portaliq', 'unknown')->getStatus());
        // Unknown app.
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->action('otherapp', 'known')->getStatus());

    }//end testActionOutsideManifestIs403WithoutOutboundCall()

    public function testActionForbiddenNeverRecordsAnAuditEntry(): void
    {
        // portal-session-hardening-v2 T09: a `forward` is only auditable once
        // the manifest AUTHORISES it — a 403 must never write an entry.
        $aggregate = $this->aggregate(actions: []);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->expects($this->never())->method('record');

        $controller = $this->controller(aggregate: $aggregate, auditor: $auditor);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->action('portaliq', 'unknown')->getStatus());

    }//end testActionForbiddenNeverRecordsAnAuditEntry()

    public function testActionSsrfAndTrustGuardsFailClosed(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'remote', 'endpoint' => 'https://evil.example/x'],
                ['id' => 'schemeRelative', 'endpoint' => '//evil.example/x'],
                ['id' => 'relative', 'endpoint' => 'apps/x/api/y'],
                ['id' => 'noEndpoint', 'endpoint' => ''],
                ['id' => 'badMethod', 'endpoint' => '/apps/x/api/y', 'method' => 'TRACE'],
                ['id' => 'gated', 'endpoint' => '/apps/x/api/y', 'minTrust' => 'high'],
            ]
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->expects($this->never())->method('newClient');

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService);

        foreach (['remote', 'schemeRelative', 'relative', 'noEndpoint', 'badMethod', 'gated'] as $actionId) {
            $response = $controller->action('portaliq', $actionId);
            $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), "action '{$actionId}' must fail closed");
        }

    }//end testActionSsrfAndTrustGuardsFailClosed()

    public function testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes(): void
    {
        // portal-contribution-endpoint-actions: a declared `fields` whitelist
        // rebuilds the forwarded body server-side — a client-supplied
        // `subjectRef` (or any other undeclared field) must never reach the
        // domain app through this path either.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'submitAccreditation', 'endpoint' => '/apps/demo/api/portal/accreditations', 'method' => 'POST', 'fields' => ['reason']],
            ]
        );

        $captured = [];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{}');
        $response->method('getStatusCode')->willReturn(200);

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$captured, $response) {
                $captured = $options;
                return $response;
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        // The request mock's getParam() (set up in controller()) returns
        // 'title' => 'X' and 'claims' => [...] — neither is 'reason', so the
        // whitelisted body must be empty; 'reason' is simply absent from the
        // fixture's params, proving undeclared fields never leak through
        // even when present on the request.
        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService);
        $result     = $controller->action('portaliq', 'submitAccreditation');

        $this->assertSame(200, $result->getStatus());
        // json_encode([]) is '[]' (an empty PHP array is ambiguous between
        // object/array) — the point being tested is that it is EMPTY, not '{}'.
        $this->assertSame('[]', $captured['body']);

    }//end testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes()

    public function testActionWithoutDeclaredFieldsForwardsTheRawBodyUnchanged(): void
    {
        // Backward compatible: an action with no `fields` declaration (e.g.
        // the demo's GET-method actions) keeps relaying the raw request body.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'noFields', 'endpoint' => '/apps/demo/api/x', 'method' => 'GET'],
            ]
        );

        $captured = [];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{}');
        $response->method('getStatusCode')->willReturn(200);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturnCallback(
            function (string $uri, array $options) use (&$captured, $response) {
                $captured = $options;
                return $response;
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService);
        $controller->action('portaliq', 'noFields');

        // The controller()-built request mock has no getContent() method, so
        // requestBody() degrades to an empty string — proving THIS path (not
        // the whitelist one) is the one that ran.
        $this->assertSame('', $captured['body']);

    }//end testActionWithoutDeclaredFieldsForwardsTheRawBodyUnchanged()

    public function testActionRecordsAForwardAuditEntryBeforeTheOutboundCall(): void
    {
        // portal-session-hardening-v2 T09: `forward` has no register/schema of
        // its own, so appId/actionId ride in their place (design.md); `id` is
        // empty. Recorded once authorised, regardless of the domain response.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'noFields', 'endpoint' => '/apps/demo/api/x', 'method' => 'GET'],
            ]
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{}');
        $response->method('getStatusCode')->willReturn(200);

        $client = $this->createMock(IClient::class);
        $client->method('get')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->expects($this->once())->method('record')->with(
            'forward',
            's1',
            'org-1',
            'portaliq',
            'noFields',
            '',
            'session-jti-1'
        );

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService, auditor: $auditor);
        $controller->action('portaliq', 'noFields');

    }//end testActionRecordsAForwardAuditEntryBeforeTheOutboundCall()

    public function testActionForwardsWithAssertionAndRelaysResponse(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'requestRenewal', 'endpoint' => '/apps/demo/api/portal/renewals', 'method' => 'POST'],
            ]
        );

        $captured = [];
        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"accepted":true}');
        $response->method('getStatusCode')->willReturn(201);

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturnCallback(
            function (string $uri, array $options) use (&$captured, $response) {
                $captured = ['uri' => $uri, 'options' => $options];
                return $response;
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService);
        $result     = $controller->action('portaliq', 'requestRenewal');

        // The domain app's status + JSON body are relayed as-is.
        $this->assertSame(201, $result->getStatus());
        $this->assertSame(['accepted' => true], $result->getData());
        // Forwarded to the resolved instance-local URL.
        $this->assertSame('https://cloud.example/apps/demo/api/portal/renewals', $captured['uri']);
        // The signed subject assertion travels; the client's own bearer NEVER does.
        $this->assertSame('assertion-jwt', $captured['options']['headers']['X-Portal-Subject']);
        $this->assertArrayNotHasKey('Authorization', $captured['options']['headers']);

    }//end testActionForwardsWithAssertionAndRelaysResponse()

    /**
     * WMEBV (wmebv-submission-receipts): the create branch of action() — a
     * matched action declaring `type: create` whose forward relays a 2xx
     * status fires the SAME receipt follow-on as the direct create() path,
     * rebuilding the whitelisted map from the action's OWN `fields` (never the
     * raw relayed body).
     */
    public function testActionWithTypeCreateAndSuccessfulForwardTriggersReceiptRecord(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'submitAccreditation', 'type' => 'create', 'endpoint' => '/apps/demo/api/portal/accreditations', 'method' => 'POST', 'fields' => ['title']],
            ]
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"accepted":true}');
        $response->method('getStatusCode')->willReturn(201);

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $received       = [];
        $receiptService = $this->createMock(SubmissionReceiptService::class);
        $receiptService->expects($this->once())->method('record')->willReturnCallback(
            function (string $subjectRef, string $organisation, string $appId, string $actionId, array $whitelistedData) use (&$received) {
                $received = compact('subjectRef', 'organisation', 'appId', 'actionId', 'whitelistedData');
            }
        );

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService, receiptService: $receiptService);
        $result     = $controller->action('portaliq', 'submitAccreditation');

        $this->assertSame(201, $result->getStatus());
        $this->assertSame('s1', $received['subjectRef']);
        $this->assertSame('org-1', $received['organisation']);
        $this->assertSame('portaliq', $received['appId']);
        $this->assertSame('submitAccreditation', $received['actionId']);
        $this->assertSame(['title' => 'X'], $received['whitelistedData']);

    }//end testActionWithTypeCreateAndSuccessfulForwardTriggersReceiptRecord()

    /**
     * WMEBV: a `type: create` forward that the domain app itself rejects
     * (non-2xx) never fires the receipt — nothing was actually created.
     */
    public function testActionWithTypeCreateAndFailedForwardNeverTriggersReceiptRecord(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'submitAccreditation', 'type' => 'create', 'endpoint' => '/apps/demo/api/portal/accreditations', 'method' => 'POST', 'fields' => ['title']],
            ]
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"error":"invalid"}');
        $response->method('getStatusCode')->willReturn(422);

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $receiptService = $this->createMock(SubmissionReceiptService::class);
        $receiptService->expects($this->never())->method('record');

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService, receiptService: $receiptService);
        $result     = $controller->action('portaliq', 'submitAccreditation');

        $this->assertSame(422, $result->getStatus());

    }//end testActionWithTypeCreateAndFailedForwardNeverTriggersReceiptRecord()

    /**
     * WMEBV: a non-create endpoint action (no `type` declared, e.g. the demo
     * health-check forward) never fires the receipt regardless of status.
     */
    public function testActionWithoutTypeCreateNeverTriggersReceiptRecord(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'requestRenewal', 'endpoint' => '/apps/demo/api/portal/renewals', 'method' => 'POST'],
            ]
        );

        $response = $this->createMock(IResponse::class);
        $response->method('getBody')->willReturn('{"accepted":true}');
        $response->method('getStatusCode')->willReturn(201);

        $client = $this->createMock(IClient::class);
        $client->method('post')->willReturn($response);

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $receiptService = $this->createMock(SubmissionReceiptService::class);
        $receiptService->expects($this->never())->method('record');

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService, receiptService: $receiptService);
        $controller->action('portaliq', 'requestRenewal');

        $this->addToAssertionCount(1);

    }//end testActionWithoutTypeCreateNeverTriggersReceiptRecord()

    public function testActionTransportFailureIs502ForwardFailed(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'flaky', 'endpoint' => '/apps/demo/api/x', 'method' => 'GET'],
            ]
        );

        $client = $this->createMock(IClient::class);
        $client->method('get')->willThrowException(new RuntimeException('connection refused'));

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($client);

        $controller = $this->controller(aggregate: $aggregate, clientService: $clientService);
        $result     = $controller->action('portaliq', 'flaky');

        $this->assertSame(Http::STATUS_BAD_GATEWAY, $result->getStatus());
        $this->assertSame(['error' => 'forward_failed'], $result->getData());

    }//end testActionTransportFailureIs502ForwardFailed()

    public function testUnauthenticatedActionIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->action('portaliq', 'x')->getStatus());

    }//end testUnauthenticatedActionIs401()

    /**
     * When two collections share a register+schema, the `collection` query
     * param selects which one is read — the direct view and a scopeClaim view
     * of the same schema must be individually addressable (portaliq#18).
     *
     * @return void
     */
    public function testCollectionParamDisambiguatesSharedSchema(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'direct', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef'],
                ['id' => 'claimed', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'scopeClaim' => 'exampleContactId'],
            ]
        );

        // Requesting the second collection by id resolves the scopeClaim view.
        $received = [];
        $this->controllerWithCollectionParam($aggregate, 'claimed', $this->readerCapturing($received))
            ->collection('portaliq', 'exampleDocument');
        $this->assertSame('exampleContactId', $received['scopeClaim']);

        // No id (empty) keeps the first-match fallback — the direct view.
        $received = [];
        $this->controllerWithCollectionParam($aggregate, '', $this->readerCapturing($received))
            ->collection('portaliq', 'exampleDocument');
        $this->assertSame('', $received['scopeClaim']);

    }//end testCollectionParamDisambiguatesSharedSchema()

    public function testObjectUnauthenticatedIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->object('r1', 'a', 'id-1')->getStatus());

    }//end testObjectUnauthenticatedIs401()

    public function testObjectOutsideManifestIs403BeforeAnyRead(): void
    {
        // No collection grants (r1, a) → forbidden, reader never called.
        $reader = $this->createMock(PortalObjectReader::class);
        $reader->expects($this->never())->method('readObject');

        $controller = $this->controller(aggregate: $this->aggregate(), reader: $reader);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->object('r1', 'a', 'id-1')->getStatus());

    }//end testObjectOutsideManifestIs403BeforeAnyRead()

    public function testObjectBelowTrustThresholdIs403BeforeAnyRead(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'r1', 'schema' => 'a', 'minTrust' => 'high'],
            ]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->expects($this->never())->method('readObject');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->object('r1', 'a', 'id-1')->getStatus());

    }//end testObjectBelowTrustThresholdIs403BeforeAnyRead()

    /**
     * A null from the reader (foreign-owned OR non-existent — indistinguishable)
     * is a single 404: no existence oracle.
     */
    public function testObjectNullFromReaderIs404NoOracle(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'r1', 'schema' => 'a', 'scopeField' => 'subjectRef'],
            ]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(null);

        $controller = $this->controller(aggregate: $aggregate, reader: $reader);
        $response   = $controller->object('r1', 'a', 'id-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $response->getData());

    }//end testObjectNullFromReaderIs404NoOracle()

    public function testObjectReturnsTheSubjectsObjectAndPassesScopeParams(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'fields' => ['title']],
            ]
        );

        $received = [];
        $reader   = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturnCallback(
            function (
                string $register,
                string $schema,
                string $scopeField,
                string $subjectRef,
                string $id,
                string $organisation='',
                string $scopeClaim='',
                string $contributingApp='',
                mixed $via=null,
                string $audience='',
                mixed $fields=null
            ) use (&$received) {
                $received = [
                    'register'        => $register,
                    'schema'          => $schema,
                    'scopeField'      => $scopeField,
                    'subjectRef'      => $subjectRef,
                    'id'              => $id,
                    'organisation'    => $organisation,
                    'contributingApp' => $contributingApp,
                    'audience'        => $audience,
                    'fields'          => $fields,
                ];
                return ['title' => 'Mine', 'id' => 'd-1'];
            }
        );

        $controller = $this->controller(aggregate: $aggregate, reader: $reader);
        $response   = $controller->object('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['object' => ['title' => 'Mine', 'id' => 'd-1']], $response->getData());
        // The client-supplied id + the collection's scope params reach the reader.
        $this->assertSame('d-1', $received['id']);
        $this->assertSame('subjectRef', $received['scopeField']);
        $this->assertSame('s1', $received['subjectRef']);
        $this->assertSame('org-1', $received['organisation']);
        $this->assertSame('portaliq', $received['contributingApp']);
        $this->assertSame(['title'], $received['fields']);

    }//end testObjectReturnsTheSubjectsObjectAndPassesScopeParams()

    public function testUpdateUnauthenticatedIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->update('r1', 'a', 'id-1')->getStatus());

    }//end testUpdateUnauthenticatedIs401()

    public function testUpdateWithoutAnUpdateActionIs403BeforeAnyWrite(): void
    {
        // A create action for the same schema must NOT authorise an update.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->update('r1', 'a', 'id-1')->getStatus());

    }//end testUpdateWithoutAnUpdateActionIs403BeforeAnyWrite()

    public function testUpdateBelowTrustThresholdIs403BeforeAnyWrite(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'minTrust' => 'high'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $this->assertSame(Http::STATUS_FORBIDDEN, $controller->update('r1', 'a', 'id-1')->getStatus());

    }//end testUpdateBelowTrustThresholdIs403BeforeAnyWrite()

    /**
     * The update body is whitelisted to the action's fields (never `claims`),
     * the client-supplied id travels to the writer, and the projected object
     * is returned.
     */
    public function testUpdateAppliesWhitelistAndReturnsUpdatedObject(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title', 'claims']],
            ]
        );

        $received = [];
        $writer   = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
                $received = ['id' => $id, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];
                return ['id' => $id, 'title' => 'X'];
            }
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->update('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['object' => ['id' => 'd-1', 'title' => 'X']], $response->getData());
        // Whitelist applied; `claims` dropped even though mistakenly declared.
        $this->assertSame(['title' => 'X'], $received['data']);
        $this->assertArrayNotHasKey('claims', $received['data']);
        // The client id + server-derived scope reach the writer.
        $this->assertSame('d-1', $received['id']);
        $this->assertSame('s1', $received['subjectRef']);
        $this->assertSame('org-1', $received['organisation']);

    }//end testUpdateAppliesWhitelistAndReturnsUpdatedObject()

    public function testUpdateRecordsAnUpdateAuditEntryWithTheClientId(): void
    {
        // portal-session-hardening-v2 T09: a successful update() records an
        // `update` audit entry carrying the (ownership-verified) client id.
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturn(['id' => 'd-1', 'title' => 'X']);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->expects($this->once())->method('record')->with(
            'update',
            's1',
            'org-1',
            'portaliq',
            'exampleDocument',
            'd-1',
            'session-jti-1'
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, auditor: $auditor);
        $response   = $controller->update('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

    }//end testUpdateRecordsAnUpdateAuditEntryWithTheClientId()

    public function testUpdateOwnershipFailureNeverRecordsAnAuditEntry(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturn(null);

        $auditor = $this->createMock(AuditTrailService::class);
        $auditor->expects($this->never())->method('record');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, auditor: $auditor);
        $response   = $controller->update('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUpdateOwnershipFailureNeverRecordsAnAuditEntry()

    /**
     * portal-notifications-dispatch: a SUCCESSFUL update fires
     * NotificationDispatchService::dispatch() with the `status.changed` rule
     * key, the matched app id, and the FULL resolved subject (audience is
     * required to resolve the app's manifest via the registry).
     */
    public function testSuccessfulUpdateTriggersStatusChangedDispatch(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturn(['id' => 'd-1', 'title' => 'X']);

        $received             = [];
        $notificationDispatch = $this->createMock(NotificationDispatchService::class);
        $notificationDispatch->expects($this->once())->method('dispatch')->willReturnCallback(
            function (string $ruleKey, string $appId, array $subject) use (&$received) {
                $received = compact('ruleKey', 'appId', 'subject');
            }
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, notificationDispatch: $notificationDispatch);
        $response   = $controller->update('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(NotificationDispatchService::RULE_STATUS_CHANGED, $received['ruleKey']);
        $this->assertSame('portaliq', $received['appId']);
        $this->assertSame(self::SUBJECT, $received['subject']);

    }//end testSuccessfulUpdateTriggersStatusChangedDispatch()

    /**
     * A failed/unauthorised update (404, before any write) never fires the
     * dispatch — nothing changed, so nothing should be notified about.
     */
    public function testFailedUpdateNeverTriggersStatusChangedDispatch(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturn(null);

        $notificationDispatch = $this->createMock(NotificationDispatchService::class);
        $notificationDispatch->expects($this->never())->method('dispatch');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer, notificationDispatch: $notificationDispatch);
        $response   = $controller->update('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testFailedUpdateNeverTriggersStatusChangedDispatch()

    /**
     * A claim-scoped transition (portal-status-transitions): the update action
     * declares a scopeClaim, so the reader resolves the ownership value from the
     * subject's portalAccount and THAT value — not the raw subjectRef — reaches
     * the writer as the scope value it re-verifies row ownership against. This is
     * what lets, e.g., a manager approve timesheets scoped by their costCenter
     * claim. A claim that cannot resolve (null) is a 404 with no write.
     */
    public function testClaimScopedUpdateResolvesTheScopeValueForOwnership(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'approve', 'type' => 'update', 'register' => 'hrmq', 'schema' => 'timesheet', 'scopeField' => 'costCenter', 'scopeClaim' => 'costCenter', 'fields' => ['status'], 'set' => ['status' => 'approved']],
            ]
        );

        $received = [];
        $writer   = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
                $received = ['scopeField' => $scopeField, 'scopeValue' => $subjectRef, 'data' => $data];
                return ['id' => $id, 'status' => 'approved'];
            }
        );

        // The reader resolves the costCenter claim to CC-100.
        $reader = $this->createMock(PortalObjectReader::class);
        // The contributing app (the scopeClaim namespace) is taken from the
        // contribution, here 'portaliq' per the aggregate() fixture.
        $reader->expects($this->once())->method('resolveScopeValue')
            ->with('costCenter', 'portaliq', self::SUBJECT)
            ->willReturn('CC-100');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, writer: $writer);
        $response   = $controller->update('hrmq', 'timesheet', 't-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        // The RESOLVED claim value (not the subjectRef) is the ownership value,
        // and the server-enforced set is applied.
        $this->assertSame('costCenter', $received['scopeField']);
        $this->assertSame('CC-100', $received['scopeValue']);
        $this->assertSame(['status' => 'approved'], $received['data']);

    }//end testClaimScopedUpdateResolvesTheScopeValueForOwnership()

    /**
     * A declared scopeClaim that cannot resolve (absent claim) → 404, no write.
     */
    public function testClaimScopedUpdateWithUnresolvableClaimIs404BeforeAnyWrite(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'approve', 'type' => 'update', 'register' => 'hrmq', 'schema' => 'timesheet', 'scopeField' => 'costCenter', 'scopeClaim' => 'costCenter', 'fields' => ['status'], 'set' => ['status' => 'approved']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('resolveScopeValue')->willReturn(null);

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, writer: $writer);
        $response   = $controller->update('hrmq', 'timesheet', 't-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testClaimScopedUpdateWithUnresolvableClaimIs404BeforeAnyWrite()

    // -- portal-inbox-v2 (T02/T03): unified inbox + tamper-proof mark-read --

    public function testInboxUnauthenticatedIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->inbox()->getStatus());

    }//end testInboxUnauthenticatedIs401()

    /**
     * inbox() delegates the aggregate straight to PortalInboxReader — the
     * merge/sort/provenance logic is PortalInboxReader's own responsibility
     * (see PortalInboxReaderTest); this test only proves the controller wires
     * the subject + aggregate through and relays the result.
     */
    public function testInboxReturnsTheAggregatedMessages(): void
    {
        $aggregate = $this->aggregate(collections: [['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage']]);

        $messages    = [['subject' => 'Hallo', '_source' => ['appId' => 'portaliq', 'label' => 'Portaliq']]];
        $inboxReader = $this->createMock(PortalInboxReader::class);
        $inboxReader->expects($this->once())->method('aggregateInbox')
            ->with(self::SUBJECT, $aggregate)
            ->willReturn($messages);

        $controller = $this->controller(aggregate: $aggregate, inboxReader: $inboxReader);
        $response   = $controller->inbox();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['messages' => $messages], $response->getData());

    }//end testInboxReturnsTheAggregatedMessages()

    public function testMarkReadUnauthenticatedIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->markRead('r1', 'a', 'id-1')->getStatus());

    }//end testMarkReadUnauthenticatedIs401()

    public function testMarkReadOutsideSubjectsManifestIs403IdorGuard(): void
    {
        // (register, schema) never appears in the subject's own contributions.
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'inbox', 'kind' => 'inbox', 'register' => 'r1', 'schema' => 'a'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->markRead('r-not-granted', 'schema-not-granted', 'id-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testMarkReadOutsideSubjectsManifestIs403IdorGuard()

    /**
     * A collection matching (register, schema) that is NOT declared
     * `kind: inbox` must never be reachable through the mark-read endpoint —
     * it narrows to inbox collections only, distinct from the plain IDOR guard.
     */
    public function testMarkReadOnANonInboxCollectionIs403(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'exampleCollection', 'register' => 'portaliq', 'schema' => 'exampleDocument'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->markRead('portaliq', 'exampleDocument', 'id-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testMarkReadOnANonInboxCollectionIs403()

    public function testMarkReadBelowTrustThresholdIs403BeforeAnyWrite(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'minTrust' => 'substantial'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->markRead('portaliq', 'portalMessage', 'm-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testMarkReadBelowTrustThresholdIs403BeforeAnyWrite()

    /**
     * On a subject's own message: the writer receives a LITERAL `['read' => true]`
     * payload — never anything derived from the request body — so no other
     * field can ever be written through this endpoint.
     */
    public function testMarkReadSetsOnlyTheReadFieldOnTheSubjectsOwnMessage(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage'],
            ]
        );

        $received = [];
        $writer   = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturnCallback(
            function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
                $received = ['id' => $id, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];
                return ['id' => $id, 'subject' => 'Hallo', 'read' => true];
            }
        );

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->markRead('portaliq', 'portalMessage', 'm-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['object' => ['id' => 'm-1', 'subject' => 'Hallo', 'read' => true]], $response->getData());
        // ONLY `read` reaches the writer — a tamper attempt via other body
        // fields is never even read (whitelist() is not invoked for this path).
        $this->assertSame(['read' => true], $received['data']);
        $this->assertSame('m-1', $received['id']);
        $this->assertSame('s1', $received['subjectRef']);
        $this->assertSame('org-1', $received['organisation']);

    }//end testMarkReadSetsOnlyTheReadFieldOnTheSubjectsOwnMessage()

    /**
     * A foreign-owned or non-existent message id: the writer's own ownership
     * re-verification returns null (no write happened, identical to every
     * other scoped write), and the controller answers the SAME 404 — no
     * existence oracle.
     */
    public function testMarkReadOnAForeignOrAbsentMessageIs404WithNoWrite(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->once())->method('updateObject')->willReturn(null);

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->markRead('portaliq', 'portalMessage', 'not-mine');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $response->getData());

    }//end testMarkReadOnAForeignOrAbsentMessageIs404WithNoWrite()

    /**
     * A declared scopeClaim that cannot resolve on the inbox collection →
     * 404, no write — the SAME fail-closed posture as a claim-scoped update().
     */
    public function testMarkReadWithUnresolvableClaimIs404BeforeAnyWrite(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'ownerRef', 'scopeClaim' => 'ownerRef'],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->expects($this->never())->method('updateObject');

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('resolveScopeValue')->willReturn(null);

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, writer: $writer);
        $response   = $controller->markRead('portaliq', 'portalMessage', 'm-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testMarkReadWithUnresolvableClaimIs404BeforeAnyWrite()

    public function testUploadRequiresTheCollectionToOptIntoFileUploads(): void
    {
        // The collection does NOT declare filesUpload → 403, no read, no attach.
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'listable' => true]]
        );

        $fileWriter = $this->createMock(PortalFileWriter::class);
        $fileWriter->expects($this->never())->method('attachFile');

        $controller = $this->controller(aggregate: $aggregate, fileWriter: $fileWriter);
        $response   = $controller->uploadFile('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testUploadRequiresTheCollectionToOptIntoFileUploads()

    public function testUploadForeignOrAbsentObjectIs404BeforeAnyAttach(): void
    {
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'listable' => true, 'filesUpload' => true]]
        );

        // The scoped reader returns null (not the subject's / absent).
        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(null);
        $reader->method('resolveScopeValue')->willReturn('s1');

        $fileWriter = $this->createMock(PortalFileWriter::class);
        $fileWriter->expects($this->never())->method('attachFile');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileWriter: $fileWriter);
        $response   = $controller->uploadFile('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

    }//end testUploadForeignOrAbsentObjectIs404BeforeAnyAttach()

    public function testUploadAttachesTheFileToAnOwnedObject(): void
    {
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'listable' => true, 'filesUpload' => true]]
        );

        // A real temp file stands in for the multipart upload.
        $tmp = tempnam(sys_get_temp_dir(), 'portaliq-test');
        file_put_contents($tmp, 'evidence-bytes');

        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnMap([['Authorization', 'Bearer client-session-token']]);
        $request->method('getParam')->willReturn('');
        $request->method('getUploadedFile')->willReturn(['name' => 'bewijs.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => 14]);

        $registry = $this->createMock(PortalContributionRegistry::class);
        $registry->method('aggregateFor')->willReturn($aggregate);
        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn(self::SUBJECT);

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(['id' => 'd-1', 'title' => 'Mine']);

        $captured   = [];
        $fileWriter = $this->createMock(PortalFileWriter::class);
        $fileWriter->method('attachFile')->willReturnCallback(
            function (string $register, string $schema, string $id, string $fileName, string $content) use (&$captured) {
                $captured = ['id' => $id, 'fileName' => $fileName, 'content' => $content];
                return ['id' => 7, 'name' => $fileName, 'size' => strlen($content)];
            }
        );

        $controller = new ContributionController(
            $request,
            $registry,
            $session,
            $reader,
            $this->createMock(PortalObjectWriter::class),
            $fileWriter,
            $this->createMock(PortalFileReader::class),
            $this->createMock(PortalSchemaReader::class),
            $this->createMock(PortalInboxReader::class),
            $this->createMock(PortalAuditHook::class),
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(AuditTrailService::class),
            $this->createMock(SubmissionReceiptService::class),
            $this->createMock(NotificationDispatchService::class)
        );

        $response = $controller->uploadFile('portaliq', 'exampleDocument', 'd-1');
        @unlink($tmp);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        // The verified id + the sanitised basename + the file bytes reach the writer.
        $this->assertSame('d-1', $captured['id']);
        $this->assertSame('bewijs.pdf', $captured['fileName']);
        $this->assertSame('evidence-bytes', $captured['content']);
        $this->assertSame(['file' => ['id' => 7, 'name' => 'bewijs.pdf', 'size' => 14]], $response->getData());

    }//end testUploadAttachesTheFileToAnOwnedObject()

    /**
     * A null from the writer (ownership re-verification failed — the write did
     * not happen) is 404, indistinguishable from a non-existent id.
     */
    public function testUpdateOwnershipFailureFromWriterIs404(): void
    {
        $aggregate = $this->aggregate(
            actions: [
                ['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
            ]
        );

        $writer = $this->createMock(PortalObjectWriter::class);
        $writer->method('updateObject')->willReturn(null);

        $controller = $this->controller(aggregate: $aggregate, writer: $writer);
        $response   = $controller->update('portaliq', 'exampleDocument', 'not-mine');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $response->getData());

    }//end testUpdateOwnershipFailureFromWriterIs404()

    /**
     * portal-document-download: the object() response is enriched with a safe
     * `_files` listing only when the matched collection opts in.
     */
    public function testObjectAttachesFilesListingOnlyWhenCollectionOptsIn(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'filesDownload' => true],
            ]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(['id' => 'd-1', 'title' => 'Mine']);

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->once())->method('listFiles')->with('portaliq', 'exampleDocument', 'd-1')->willReturn([['id' => 7, 'name' => 'besluit.pdf', 'size' => 10]]);

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader);
        $response   = $controller->object('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame([['id' => 7, 'name' => 'besluit.pdf', 'size' => 10]], $response->getData()['object']['_files']);

    }//end testObjectAttachesFilesListingOnlyWhenCollectionOptsIn()

    public function testObjectNeverAttachesFilesListingWhenCollectionDoesNotOptIn(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef'],
            ]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(['id' => 'd-1', 'title' => 'Mine']);

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->never())->method('listFiles');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader);
        $response   = $controller->object('portaliq', 'exampleDocument', 'd-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayNotHasKey('_files', $response->getData()['object']);

    }//end testObjectNeverAttachesFilesListingWhenCollectionDoesNotOptIn()

    public function testDownloadUnauthenticatedIs401(): void
    {
        $controller = $this->controller(aggregate: $this->aggregate(), subject: null);
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->downloadFile('r1', 'a', 'id-1', 'f-1')->getStatus());

    }//end testDownloadUnauthenticatedIs401()

    public function testDownloadOutsideManifestIs403BeforeAnyRead(): void
    {
        $reader = $this->createMock(PortalObjectReader::class);
        $reader->expects($this->never())->method('readObject');

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->never())->method('streamFile');

        $controller = $this->controller(aggregate: $this->aggregate(), reader: $reader, fileReader: $fileReader);
        $response   = $controller->downloadFile('r1', 'a', 'id-1', 'f-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testDownloadOutsideManifestIs403BeforeAnyRead()

    public function testDownloadBelowTrustThresholdIs403BeforeAnyRead(): void
    {
        $aggregate = $this->aggregate(
            collections: [
                ['register' => 'r1', 'schema' => 'a', 'minTrust' => 'high', 'filesDownload' => true],
            ]
        );

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->never())->method('streamFile');

        $controller = $this->controller(aggregate: $aggregate, fileReader: $fileReader);
        $response   = $controller->downloadFile('r1', 'a', 'id-1', 'f-1');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

    }//end testDownloadBelowTrustThresholdIs403BeforeAnyRead()

    /**
     * A collection that has NOT declared `filesDownload: true` refuses BEFORE
     * any OpenRegister read, with the identical 404 body every other refusal
     * uses (no existence oracle).
     */
    public function testDownloadRequiresTheCollectionToOptIntoFileDownloads(): void
    {
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef']]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->expects($this->never())->method('readObject');

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->never())->method('streamFile');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader);
        $response   = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'f-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $response->getData());

    }//end testDownloadRequiresTheCollectionToOptIntoFileDownloads()

    /**
     * A foreign-owned or non-existent object is refused with the IDENTICAL 404
     * BEFORE the file layer is ever touched.
     */
    public function testDownloadForeignOrAbsentObjectIs404BeforeAnyStream(): void
    {
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'filesDownload' => true]]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(null);

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->never())->method('streamFile');

        $auditHook = $this->createMock(PortalAuditHook::class);
        $auditHook->expects($this->never())->method('download');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader, auditHook: $auditHook);
        $response   = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'f-1');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $response->getData());

    }//end testDownloadForeignOrAbsentObjectIs404BeforeAnyStream()

    /**
     * A `fileId` that does not resolve (non-existent, or foreign to the owned
     * object's folder) is the SAME 404 as the two refusals above — no oracle.
     */
    public function testDownloadNonExistentFileIs404IdenticalToOtherRefusals(): void
    {
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'filesDownload' => true]]
        );

        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturn(['id' => 'd-1', 'title' => 'Mine']);

        $fileReader = $this->createMock(PortalFileReader::class);
        $fileReader->method('streamFile')->willReturn(null);

        $auditHook = $this->createMock(PortalAuditHook::class);
        $auditHook->expects($this->never())->method('download');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader, auditHook: $auditHook);
        $response   = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'not-mine');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'not_found'], $response->getData());

    }//end testDownloadNonExistentFileIs404IdenticalToOtherRefusals()

    /**
     * On success: ownership is re-verified BEFORE the file layer runs, the
     * resolved stream is returned to the client, and the audit hook is invoked
     * with the verb `download` and the target register/schema/id.
     */
    public function testDownloadStreamsOwnedFileAndInvokesAuditHookOnSuccess(): void
    {
        $aggregate = $this->aggregate(
            collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'filesDownload' => true]]
        );

        $received = [];
        $reader   = $this->createMock(PortalObjectReader::class);
        $reader->method('readObject')->willReturnCallback(
            function (
                string $register,
                string $schema,
                string $scopeField,
                string $subjectRef,
                string $id,
                string $organisation='',
                string $scopeClaim='',
                string $contributingApp='',
                mixed $via=null,
                string $audience='',
                mixed $fields=null
            ) use (&$received) {
                $received = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'id' => $id];
                return ['id' => 'd-1', 'title' => 'Mine'];
            }
        );

        $expectedStream = $this->createMock(StreamResponse::class);
        $fileReader     = $this->createMock(PortalFileReader::class);
        $fileReader->expects($this->once())->method('streamFile')->with('portaliq', 'exampleDocument', 'd-1', 'f-1')->willReturn($expectedStream);

        $auditHook = $this->createMock(PortalAuditHook::class);
        $auditHook->expects($this->once())->method('download')->with('s1', 'org-1', 'portaliq', 'exampleDocument', 'd-1');

        $controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader, auditHook: $auditHook);
        $response   = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'f-1');

        $this->assertSame($expectedStream, $response);
        // Ownership was re-verified through the SAME scoped path as object()/update()
        // BEFORE the file layer ran.
        $this->assertSame('d-1', $received['id']);
        $this->assertSame('subjectRef', $received['scopeField']);
        $this->assertSame('s1', $received['subjectRef']);

    }//end testDownloadStreamsOwnedFileAndInvokesAuditHookOnSuccess()

    /**
     * A controller whose request returns the given value for the `collection`
     * query param (and safe defaults for the rest).
     */
    private function controllerWithCollectionParam(array $aggregate, string $collectionId, PortalObjectReader $reader): ContributionController
    {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnMap([['Authorization', 'Bearer client-session-token']]);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, $default=null) => ($key === 'collection' ? $collectionId : $default)
        );

        $registry = $this->createMock(PortalContributionRegistry::class);
        $registry->method('aggregateFor')->willReturn($aggregate);

        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn(self::SUBJECT);

        return new ContributionController(
            $request,
            $registry,
            $session,
            $reader,
            $this->createMock(PortalObjectWriter::class),
            $this->createMock(PortalFileWriter::class),
            $this->createMock(PortalFileReader::class),
            $this->createMock(PortalSchemaReader::class),
            $this->createMock(PortalInboxReader::class),
            $this->createMock(PortalAuditHook::class),
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class),
            $this->createMock(AuditTrailService::class),
            $this->createMock(SubmissionReceiptService::class),
            $this->createMock(NotificationDispatchService::class)
        );

    }//end controllerWithCollectionParam()

    /**
     * A reader mock whose readCollection() records every received argument
     * into the given array (by reference) and returns no rows.
     */
    private function readerCapturing(array &$received): PortalObjectReader
    {
        $reader = $this->createMock(PortalObjectReader::class);
        $reader->method('readCollection')->willReturnCallback(
            function (
                string $register,
                string $schema,
                string $scopeField,
                string $subjectRef,
                string $organisation='',
                int $limit=200,
                string $scopeClaim='',
                string $contributingApp='',
                mixed $via=null,
                string $audience='',
                mixed $fields=null
            ) use (&$received) {
                $received = [
                    'register'        => $register,
                    'schema'          => $schema,
                    'scopeField'      => $scopeField,
                    'subjectRef'      => $subjectRef,
                    'organisation'    => $organisation,
                    'limit'           => $limit,
                    'scopeClaim'      => $scopeClaim,
                    'contributingApp' => $contributingApp,
                    'via'             => $via,
                    'audience'        => $audience,
                    'fields'          => $fields,
                ];
                return [];
            }
        );
        return $reader;

    }//end readerCapturing()

    /**
     * Build a controller with a canned aggregate + subject and optional
     * collaborator overrides.
     */
    private function controller(
        array $aggregate,
        ?array $subject=self::SUBJECT,
        ?PortalObjectReader $reader=null,
        ?PortalObjectWriter $writer=null,
        ?IClientService $clientService=null,
        ?PortalFileWriter $fileWriter=null,
        ?PortalFileReader $fileReader=null,
        ?PortalAuditHook $auditHook=null,
        ?PortalInboxReader $inboxReader=null,
        ?AuditTrailService $auditor=null,
        ?SubmissionReceiptService $receiptService=null,
        ?NotificationDispatchService $notificationDispatch=null,
        ?array $anonymousAggregate=null
    ): ContributionController {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnMap([['Authorization', 'Bearer client-session-token']]);
        $request->method('getParam')->willReturnCallback(
            function (string $key) {
                $params = [
                    'title'  => 'X',
                    'claims' => ['portaliq' => ['exampleContactId' => 'HACKED']],
                ];
                return ($params[$key] ?? null);
            }
        );

        $registry = $this->createMock(PortalContributionRegistry::class);
        $registry->method('aggregateFor')->willReturn($aggregate);
        // portal-page-provisioning: defaults to an empty anonymous surface
        // (byte-identical to pre-change behaviour) unless a test explicitly
        // wires an anonymous aggregate.
        $registry->method('aggregateAnonymous')->willReturn($anonymousAggregate ?? ['contributions' => []]);

        $session = $this->createMock(PortalSessionService::class);
        $session->method('resolveFromBearer')->willReturn($subject);
        $session->method('issueAssertion')->willReturn('assertion-jwt');

        $urlGenerator = $this->createMock(IURLGenerator::class);
        $urlGenerator->method('getAbsoluteURL')->willReturnCallback(
            fn (string $path) => 'https://cloud.example'.$path
        );

        // Default reader: resolveScopeValue returns the subject's subjectRef (the
        // real no-scopeClaim behaviour) so update()'s ownership resolution passes.
        $defaultReader = $this->createMock(PortalObjectReader::class);
        $defaultReader->method('resolveScopeValue')->willReturn((string) ($subject['subjectRef'] ?? ''));

        return new ContributionController(
            $request,
            $registry,
            $session,
            ($reader ?? $defaultReader),
            ($writer ?? $this->createMock(PortalObjectWriter::class)),
            ($fileWriter ?? $this->createMock(PortalFileWriter::class)),
            ($fileReader ?? $this->createMock(PortalFileReader::class)),
            $this->createMock(PortalSchemaReader::class),
            ($inboxReader ?? $this->createMock(PortalInboxReader::class)),
            ($auditHook ?? $this->createMock(PortalAuditHook::class)),
            ($clientService ?? $this->createMock(IClientService::class)),
            $urlGenerator,
            ($auditor ?? $this->createMock(AuditTrailService::class)),
            ($receiptService ?? $this->createMock(SubmissionReceiptService::class)),
            ($notificationDispatch ?? $this->createMock(NotificationDispatchService::class))
        );

    }//end controller()

    /**
     * A one-contribution aggregate shaped like the registry's output.
     */
    private function aggregate(array $collections=[], array $actions=[]): array
    {
        return [
            'audience'      => 'supplier',
            'organisation'  => 'org-1',
            'contributions' => [
                [
                    'app'         => 'portaliq',
                    'label'       => 'Test',
                    'collections' => $collections,
                    'actions'     => $actions,
                ],
            ],
        ];

    }//end aggregate()

}//end class
