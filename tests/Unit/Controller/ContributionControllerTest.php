<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
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
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class)
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
            $this->createMock(IClientService::class),
            $this->createMock(IURLGenerator::class)
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
        ?PortalFileWriter $fileWriter=null
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
            ($clientService ?? $this->createMock(IClientService::class)),
            $urlGenerator
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
