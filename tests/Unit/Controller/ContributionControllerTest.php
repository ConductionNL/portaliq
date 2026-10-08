<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\InstanceLoopback;
use OCA\Portaliq\Service\InternalBaseUrl;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PortalSchemaReader;
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
use Psr\Log\LoggerInterface;
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
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T3
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T5
 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T8
 * @spec openspec/changes/archive/2026-09-07-field-projection/tasks.md#T2
 */
class ContributionControllerTest extends TestCase {

	private const SUBJECT = [
		'subjectRef' => 's1',
		'audience' => 'supplier',
		'organisation' => 'org-1',
		'trust' => 'low',
		'roles' => [],
		'jti' => 'session-jti-1',
	];

	/**
	 * portal-page-provisioning: with NO anonymous entries anywhere, a
	 * no-bearer index() call serves the (empty) anonymous aggregate — 200,
	 * not 401. In production PortalAuthMiddleware would already have thrown
	 * before this method ran; this proves the controller's OWN behaviour in
	 * isolation.
	 */
	public function testIndexServesEmptyAnonymousAggregateWhenSubjectResolvesNullAndNoAnonymousEntries(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$response = $controller->index();

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
	public function testIndexServesAnonymousAggregateWhenSubjectResolvesNull(): void {
		$anonymousAggregate = [
			'contributions' => [
				['app' => 'portaliq', 'label' => 'Meldingen', 'collections' => [], 'actions' => [['id' => 'openIntake', 'type' => 'create', 'anonymous' => true]]],
			],
		];

		$controller = $this->controller(aggregate: $this->aggregate(), subject: null, anonymousAggregate: $anonymousAggregate);
		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($anonymousAggregate['contributions'], $response->getData()['contributions']);
		$this->assertSame(0, $response->getData()['unreadCount']);

	}//end testIndexServesAnonymousAggregateWhenSubjectResolvesNull()

	public function testIndexReturnsTheRegistrysAggregateForAnAuthenticatedSubject(): void {
		$aggregate = $this->aggregate(collections: [['register' => 'r1', 'schema' => 'a']]);
		$controller = $this->controller(aggregate: $aggregate);
		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// The aggregate is returned unchanged PLUS the unread count
		// (portal-inbox-v2 T04) — the default inbox reader stub yields 0 —
		// and the tasks announcement (portal-task-delivery): with no gateway
		// wired (this fixture's default), the surface reads disabled.
		$this->assertSame(($aggregate + ['unreadCount' => 0, 'tasks' => ['enabled' => false], 'cases' => ['enabled' => false, 'closedMarker' => false]]), $response->getData());

	}//end testIndexReturnsTheRegistrysAggregateForAnAuthenticatedSubject()

	/**
	 * A portal's navigation choice hides and orders the pages of the portal that serves the request.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/tasks.md#t02
	 */
	public function testPortalNavigationHidesAndOrdersPages(): void {
		$aggregate = [
			'audience' => 'client',
			'organisation' => 'org-1',
			'contributions' => [[
				'app' => 'pipelinq',
				'pages' => [['id' => 'quotes'], ['id' => 'invoices'], ['id' => 'cases']],
				'collections' => [],
			]],
		];
		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn([
			'slug' => 'open-tilburg',
			'navigation' => ['client' => [['page' => 'pipelinq:quotes', 'hidden' => true], ['page' => 'pipelinq:cases'], ['page' => 'pipelinq:invoices']]],
		]);

		$data = $this->controller(aggregate: $aggregate, portals: $portals)->index()->getData();

		$this->assertSame(['cases', 'invoices'], array_column($data['contributions'][0]['pages'], 'id'));
	}//end testPortalNavigationHidesAndOrdersPages()

	/**
	 * @spec openspec/changes/operate-pages-per-portal-and-client/tasks.md#t02
	 */
	public function testUnlistedPageKeepsItsPlaceAndNoChoiceAnswersAsToday(): void {
		$aggregate = [
			'audience' => 'client',
			'organisation' => 'org-1',
			'contributions' => [['app' => 'pipelinq', 'pages' => [['id' => 'quotes'], ['id' => 'documents']], 'collections' => []]],
		];

		$hidingOther = $this->createMock(PortalResolver::class);
		$hidingOther->method('resolve')->willReturn(['slug' => 'p', 'navigation' => ['client' => [['page' => 'pipelinq:quotes', 'hidden' => true]]]]);
		$this->assertSame(['documents'], array_column($this->controller(aggregate: $aggregate, portals: $hidingOther)->index()->getData()['contributions'][0]['pages'], 'id'));

		$forOtherAudience = $this->createMock(PortalResolver::class);
		$forOtherAudience->method('resolve')->willReturn(['slug' => 'p', 'navigation' => ['supplier' => [['page' => 'pipelinq:quotes', 'hidden' => true]]]]);
		$this->assertSame(['quotes', 'documents'], array_column($this->controller(aggregate: $aggregate, portals: $forOtherAudience)->index()->getData()['contributions'][0]['pages'], 'id'), 'another audience\'s choice does not apply');

		$noPortal = $this->createMock(PortalResolver::class);
		$noPortal->method('resolve')->willReturn(null);
		$this->assertSame(['quotes', 'documents'], array_column($this->controller(aggregate: $aggregate, portals: $noPortal)->index()->getData()['contributions'][0]['pages'], 'id'));
		$this->assertSame(['quotes', 'documents'], array_column($this->controller(aggregate: $aggregate)->index()->getData()['contributions'][0]['pages'], 'id'));
	}//end testUnlistedPageKeepsItsPlaceAndNoChoiceAnswersAsToday()

	/**
	 * cases-my-cases-page REQ-CMC-001: the contributions answer announces the
	 * "My cases" page when any contribution declares a `kind: cases`
	 * collection, and whether any of those declares a closed marker (the
	 * "Closed" tab shows only then).
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
	 */
	public function testIndexAnnouncesTheCasesPageAndItsClosedMarker(): void {
		$plain = $this->aggregate(collections: [['id' => 'zaken', 'kind' => 'cases', 'register' => 'r', 'schema' => 'zaak']]);
		$this->assertSame(['enabled' => true, 'closedMarker' => false], $this->controller(aggregate: $plain)->index()->getData()['cases']);

		$marked = $this->aggregate(collections: [
			['id' => 'zaken', 'kind' => 'cases', 'register' => 'r', 'schema' => 'zaak', 'closedField' => 'endDate'],
		]);
		$this->assertSame(['enabled' => true, 'closedMarker' => true], $this->controller(aggregate: $marked)->index()->getData()['cases']);

		// A closed marker on a collection of another kind announces nothing.
		$other = $this->aggregate(collections: [['id' => 'berichten', 'kind' => 'inbox', 'register' => 'r', 'schema' => 'm', 'closedField' => 'endDate']]);
		$this->assertSame(['enabled' => false, 'closedMarker' => false], $this->controller(aggregate: $other)->index()->getData()['cases']);

	}//end testIndexAnnouncesTheCasesPageAndItsClosedMarker()

	/**
	 * The contributions response carries the subject's own unread count,
	 * computed by PortalInboxReader over the SAME aggregate (portal-inbox-v2 T04).
	 */
	public function testIndexIncludesTheSubjectsUnreadCount(): void {
		$aggregate = $this->aggregate(collections: [['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage']]);

		$inboxReader = $this->createMock(PortalInboxReader::class);
		$inboxReader->expects($this->once())->method('unreadCount')
			->with(self::SUBJECT, $aggregate)
			->willReturn(3);

		$controller = $this->controller(aggregate: $aggregate, inboxReader: $inboxReader);
		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['unreadCount']);

	}//end testIndexIncludesTheSubjectsUnreadCount()

	public function testCollectionOutsideSubjectsManifestIs403IdorGuard(): void {
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
		$response = $controller->collection('r-not-granted', 'schema-not-granted');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCollectionOutsideSubjectsManifestIs403IdorGuard()

	public function testCollectionUnauthenticatedIs401(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->collection('r1', 'a')->getStatus());

	}//end testCollectionUnauthenticatedIs401()

	public function testCreateWithoutAMatchingActionIs403(): void {
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
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCreateWithoutAMatchingActionIs403()

	/**
	 * portal-page-provisioning: a no-bearer create() call with NO anonymous
	 * `type: create` action declared for this exact (register, schema) is
	 * 403 forbidden — the anonymous path never becomes an open write.
	 */
	public function testCreateUnauthenticatedWithNoAnonymousActionIs403(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createAnonymousObject');

		$controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer);
		$response = $controller->create('r1', 'a');

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
	public function testAnonymousCreateSucceedsForAnAnonymousAction(): void {
		$anonymousAggregate = [
			'contributions' => [
				[
					'app' => 'openbuild',
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
		$response = $controller->create('openbuild', 'melding');

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
	public function testAnonymousCreateRejectsANonAnonymousActionForTheSameTarget(): void {
		$anonymousAggregate = [
			'contributions' => [
				[
					'app' => 'openbuild',
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
		$response = $controller->create('openbuild', 'melding');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAnonymousCreateRejectsANonAnonymousActionForTheSameTarget()

	/**
	 * `defaults` are stamped server-side over the whitelisted payload on the
	 * anonymous path too — identical discipline to the authenticated path —
	 * so a schema's own required-but-not-client-editable field (e.g. a
	 * placeholder ownership marker) can be satisfied without a real subject.
	 */
	public function testAnonymousCreateAppliesDefaultsOverTheWhitelist(): void {
		$anonymousAggregate = [
			'contributions' => [
				[
					'app' => 'portaliq',
					'actions' => [
						[
							'id' => 'publicIntake',
							'type' => 'create',
							'register' => 'portaliq',
							'schema' => 'exampleDocument',
							'fields' => ['title'],
							'defaults' => ['subjectRef' => 'anonymous'],
							'anonymous' => true,
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
		$response = $controller->create('portaliq', 'exampleDocument');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAnonymousCreateAppliesDefaultsOverTheWhitelist()

	/**
	 * A failed anonymous OpenRegister write is relayed as 502, exactly like
	 * the authenticated path.
	 */
	public function testAnonymousCreateWriteFailureIs502(): void {
		$anonymousAggregate = [
			'contributions' => [
				['app' => 'openbuild', 'actions' => [['id' => 'x', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'anonymous' => true]]],
			],
		];

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createAnonymousObject')->willReturn(null);

		$controller = $this->controller(aggregate: $this->aggregate(), subject: null, writer: $writer, anonymousAggregate: $anonymousAggregate);
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testAnonymousCreateWriteFailureIs502()

	/**
	 * Every active landing-page form is its own anonymous create action on
	 * `landingPageSubmission`. A submission names its form's action, so it
	 * is filed under that form's whitelist and defaults, not the first one.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T3
	 */
	public function testAnonymousCreateWritesThroughTheFormItNames(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createAnonymousObject')
			->with('portaliq', 'landingPageSubmission', ['email' => 'a@example.nl', 'formId' => 'form-b'])
			->willReturn(['id' => 'new']);

		$response = $this->controller(
			aggregate: $this->aggregate(),
			subject: null,
			writer: $writer,
			anonymousAggregate: $this->twoLandingPageForms(),
			params: ['actionId' => 'submit-form-b', 'email' => 'a@example.nl', 'name' => 'Ada']
		)->create('portaliq', 'landingPageSubmission');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAnonymousCreateWritesThroughTheFormItNames()

	/**
	 * An anonymous create naming no anonymous action on the target is
	 * refused, and two forms without a name are refused rather than guessed.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T3
	 */
	public function testAnonymousCreateNamingNoFormOrAnUnknownOneIsRefused(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createAnonymousObject');

		$unknown = $this->controller(
			aggregate: $this->aggregate(),
			subject: null,
			writer: $writer,
			anonymousAggregate: $this->twoLandingPageForms(),
			params: ['actionId' => 'submit-form-c']
		)->create('portaliq', 'landingPageSubmission');
		$this->assertSame(Http::STATUS_FORBIDDEN, $unknown->getStatus());

		$unnamed = $this->controller(
			aggregate: $this->aggregate(),
			subject: null,
			writer: $writer,
			anonymousAggregate: $this->twoLandingPageForms()
		)->create('portaliq', 'landingPageSubmission');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $unnamed->getStatus());
		$this->assertSame('action_required', $unnamed->getData()['error']);

	}//end testAnonymousCreateNamingNoFormOrAnUnknownOneIsRefused()

	/**
	 * An anonymous create can never name an action that is not anonymous,
	 * even when the anonymous aggregate carries one for the same target.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T3
	 */
	public function testAnonymousCreateNamingASignedInActionIsRefused(): void {
		$aggregate = $this->twoLandingPageForms();
		$aggregate['contributions'][0]['actions'][] = ['id' => 'staffOnly', 'type' => 'create', 'register' => 'portaliq', 'schema' => 'landingPageSubmission', 'fields' => ['email']];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createAnonymousObject');

		$response = $this->controller(
			aggregate: $this->aggregate(),
			subject: null,
			writer: $writer,
			anonymousAggregate: $aggregate,
			params: ['actionId' => 'staffOnly']
		)->create('portaliq', 'landingPageSubmission');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAnonymousCreateNamingASignedInActionIsRefused()

	/**
	 * Two active landing-page forms, as PortalContributionProvider synthesises them.
	 *
	 * @return array<string, mixed>
	 */
	private function twoLandingPageForms(): array {
		return [
			'contributions' => [
				[
					'app' => 'portaliq',
					'actions' => [
						['id' => 'submit-form-a', 'type' => 'create', 'anonymous' => true, 'register' => 'portaliq', 'schema' => 'landingPageSubmission', 'fields' => ['name'], 'defaults' => ['formId' => 'form-a']],
						['id' => 'submit-form-b', 'type' => 'create', 'anonymous' => true, 'register' => 'portaliq', 'schema' => 'landingPageSubmission', 'fields' => ['email'], 'defaults' => ['formId' => 'form-b']],
					],
				],
			],
		];

	}//end twoLandingPageForms()

	public function testCollectionBelowTrustThresholdIs403BeforeAnyRead(): void {
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'r1', 'schema' => 'a', 'minTrust' => 'substantial'],
			]
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readCollection');

		$controller = $this->controller(aggregate: $aggregate, reader: $reader);
		$response = $controller->collection('r1', 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCollectionBelowTrustThresholdIs403BeforeAnyRead()

	public function testCreateBelowTrustThresholdIs403BeforeAnyWrite(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'minTrust' => 'high'],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testCreateBelowTrustThresholdIs403BeforeAnyWrite()

	public function testCollectionPassesV2ScopeParametersToReader(): void {
		$via = [
			'register' => 'zaken',
			'schema' => 'rol',
			'scopeField' => 'betrokkeneIdentificatie.inpBsn',
			'targetField' => 'zaak',
		];
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'own', 'scopeClaim' => 'linkedContactId', 'via' => $via, 'fields' => ['title', 'status']],
			]
		);

		$received = [];
		$reader = $this->readerCapturing($received);
		$controller = $this->controller(aggregate: $aggregate, reader: $reader);
		$response = $controller->collection('zaken', 'zaak');

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

	/**
	 * A row whose `visibleFromField` lies ahead is not in the list and its
	 * read by id is the shared 404 (site-school-blocks).
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
	 */
	public function testARowBeforeItsVisibleFromMomentIsNotServed(): void {
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'r1', 'schema' => 'a', 'scopeField' => 'subjectRef', 'visibleFromField' => 'visibleFrom'],
			]
		);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn([
			['id' => 'now', 'visibleFrom' => '2000-01-01T00:00:00Z'],
			['id' => 'later', 'visibleFrom' => '2999-01-01T00:00:00Z'],
		]);
		$reader->method('readObject')->willReturn(['id' => 'later', 'visibleFrom' => '2999-01-01T00:00:00Z']);

		$controller = $this->controller(aggregate: $aggregate, reader: $reader);
		$rows = $controller->collection('r1', 'a')->getData();
		$ids  = array_column(($rows['results'] ?? $rows['objects'] ?? $rows), 'id');

		$this->assertContains('now', $ids);
		$this->assertNotContains('later', $ids);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->object('r1', 'a', 'later')->getStatus());
	}//end testARowBeforeItsVisibleFromMomentIsNotServed()

	public function testInboxCollectionFieldsReachTheReaderAndAbsentFieldsStayNull(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'scopeField' => 'subjectRef', 'fields' => ['subject', 'read']],
				['register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef'],
			]
		);

		$received = [];
		$reader = $this->readerCapturing($received);
		$controller = $this->controller(aggregate: $aggregate, reader: $reader);

		// A kind:'inbox' collection may declare fields like any other.
		$this->assertSame(Http::STATUS_OK, $controller->collection('portaliq', 'portalMessage')->getStatus());
		$this->assertSame(['subject', 'read'], $received['fields']);

		// No declaration → null, which the reader treats as "full rows".
		$this->assertSame(Http::STATUS_OK, $controller->collection('portaliq', 'exampleDocument')->getStatus());
		$this->assertNull($received['fields']);

	}//end testInboxCollectionFieldsReachTheReaderAndAbsentFieldsStayNull()

	public function testCreateNeverPassesClaimsToTheWriter(): void {
		// Even a contribution that (mistakenly) whitelists `claims` must not
		// let a client-supplied claim map reach the OpenRegister write.
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title', 'claims']],
			]
		);

		$saved = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$saved) {
				$saved = $data;
				return ['id' => 'new'];
			}
		);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['title' => 'X'], $saved);
		$this->assertArrayNotHasKey('claims', $saved);

	}//end testCreateNeverPassesClaimsToTheWriter()

	/**
	 * signin-eherkenning-branch REQ-SEB-002, from the caller: a session
	 * restricted to a branch reads only that branch's rows, and nothing of a
	 * collection that declares no branch field.
	 */
	public function testARestrictedSessionReadsOnlyItsBranchsRows(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'zaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'kvk', 'branchField' => 'vestiging'],
				['id' => 'andere', 'register' => 'zaken', 'schema' => 'melding', 'scopeField' => 'kvk'],
			]
		);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn(
			[
				['id' => 'z1', 'vestiging' => '000012345678'],
				['id' => 'z2', 'vestiging' => '000087654321'],
				['id' => 'z3'],
			]
		);
		$subject = self::SUBJECT + ['branch' => '000012345678', 'branchRestricted' => true];

		$own = $this->controller(aggregate: $aggregate, subject: $subject, reader: $reader)->collection('zaken', 'zaak');
		$this->assertSame(['z1'], array_column($own->getData()['objects'], 'id'));

		$none = $this->controller(aggregate: $aggregate, subject: $subject, reader: $reader)->collection('zaken', 'melding');
		$this->assertSame([], $none->getData()['objects']);

		$chosen = $this->controller(aggregate: $aggregate, subject: self::SUBJECT + ['branch' => '000012345678', 'branchRestricted' => false], reader: $reader)->collection('zaken', 'melding');
		$this->assertCount(3, $chosen->getData()['objects'], 'a chosen branch filters only where the collection can tell branches apart');

	}//end testARestrictedSessionReadsOnlyItsBranchsRows()

	public function testAnotherBranchsCaseAnswersNotFound(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'zaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'kvk', 'branchField' => 'vestiging'],
			]
		);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn(['id' => 'z2', 'vestiging' => '000087654321']);
		$subject = self::SUBJECT + ['branch' => '000012345678', 'branchRestricted' => true];

		$response = $this->controller(aggregate: $aggregate, subject: $subject, reader: $reader)->object('zaken', 'zaak', 'z2');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnotherBranchsCaseAnswersNotFound()

	public function testACaseFiledInABranchSessionLandsOnTheBranch(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'branchField' => 'vestiging'],
			]
		);
		$saved = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$saved) {
				$saved = $data;
				return ['id' => 'new'];
			}
		);
		$subject = self::SUBJECT + ['branch' => '000012345678', 'branchRestricted' => true];

		$this->controller(aggregate: $aggregate, subject: $subject, writer: $writer)->create('r1', 'a');

		$this->assertSame(['title' => 'X', 'vestiging' => '000012345678'], $saved);

	}//end testACaseFiledInABranchSessionLandsOnTheBranch()

	/**
	 * A create action that declares `scopeClaim` stamps its scope field with
	 * the server-resolved claim, not the subject's own subjectRef. Found on a
	 * school's parent portal: learniq's guardian absence report
	 * (`scopeField: submittedByRef`, `scopeClaim: guardianRef`) was stamped
	 * with the portal account's subjectRef, which OpenRegister refused (not a
	 * uuid), so no parent could report an absence.
	 *
	 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
	 */
	public function testAClaimScopedCreateStampsTheClaim(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'scopeField' => 'submittedByRef', 'scopeClaim' => 'guardianRef'],
			]
		);
		$stamp = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef) use (&$stamp) {
				$stamp = [$scopeField, $subjectRef];
				return ['id' => 'new'];
			}
		);
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturnCallback(
			static fn (string $scopeClaim, string $contributingApp, array $subject): ?string => $scopeClaim === 'guardianRef' ? 'guardian-uuid' : null
		);

		$response = $this->controller(aggregate: $aggregate, reader: $reader, writer: $writer)->create('r1', 'a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['submittedByRef', 'guardian-uuid'], $stamp);

	}//end testAClaimScopedCreateStampsTheClaim()

	/**
	 * An absent claim refuses the create before anything is written.
	 *
	 * @spec openspec/changes/claim-scoped-create-stamps-the-claim/tasks.md#T1
	 */
	public function testAClaimScopedCreateWithoutTheClaimIsRefused(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title'], 'scopeField' => 'submittedByRef', 'scopeClaim' => 'guardianRef'],
			]
		);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('resolveScopeValue')->willReturn(null);

		$response = $this->controller(aggregate: $aggregate, reader: $reader, writer: $writer)->create('r1', 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testAClaimScopedCreateWithoutTheClaimIsRefused()

	/**
	 * Two create actions on one schema: the action the form names is the one
	 * that writes. Before, the first declared action won, so a complaint was
	 * saved with the request form's defaults and whitelist.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T1
	 */
	public function testCreateWritesThroughTheActionItNames(): void {
		$captured = null;
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, array $data) use (&$captured) {
				$captured = $data;
				return ['id' => 'new'];
			}
		);

		$response = $this->controller(
			aggregate: $this->twoCreatesOnOneSchema(),
			writer: $writer,
			params: ['actionId' => 'fileComplaint', 'title' => 'X']
		)->create('r1', 'ticket');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('complaint', $captured['ticketType']);

	}//end testCreateWritesThroughTheActionItNames()

	/**
	 * An id the subject has no create action for on this target is refused
	 * before anything is written, also when it names another target's action.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T1
	 */
	public function testCreateNamingAnUnknownActionIsRefused(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');
		$aggregate = $this->twoCreatesOnOneSchema();
		$aggregate['contributions'][0]['actions'][] = ['id' => 'elsewhere', 'type' => 'create', 'register' => 'r1', 'schema' => 'other', 'fields' => ['title']];

		foreach (['nope', 'elsewhere'] as $id) {
			$response = $this->controller(aggregate: $aggregate, writer: $writer, params: ['actionId' => $id])->create('r1', 'ticket');
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus(), $id);
		}

	}//end testCreateNamingAnUnknownActionIsRefused()

	/**
	 * Without an id and with two create actions on the target, the create is
	 * refused with a 400 that says so, rather than guessed.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T1
	 */
	public function testCreateWithoutAnIdBetweenTwoActionsIsRefusedNotGuessed(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('createObject');

		$response = $this->controller(aggregate: $this->twoCreatesOnOneSchema(), writer: $writer)->create('r1', 'ticket');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('action_required', $response->getData()['error']);

	}//end testCreateWithoutAnIdBetweenTwoActionsIsRefusedNotGuessed()

	/**
	 * Without an id and with exactly one create action on the target, the
	 * create keeps working as it did.
	 *
	 * @spec openspec/changes/create-names-its-action/tasks.md#T1
	 */
	public function testCreateWithoutAnIdAndOneActionStillWorks(): void {
		$aggregate = $this->aggregate(actions: [['id' => 'only', 'type' => 'create', 'register' => 'r1', 'schema' => 'ticket', 'fields' => ['title']]]);
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('createObject')->willReturn(['id' => 'new']);

		$response = $this->controller(aggregate: $aggregate, writer: $writer)->create('r1', 'ticket');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testCreateWithoutAnIdAndOneActionStillWorks()

	/**
	 * Two create actions writing the same schema with different defaults.
	 *
	 * @return array<string, mixed>
	 */
	private function twoCreatesOnOneSchema(): array {
		return $this->aggregate(
			actions: [
				['id' => 'fileRequest', 'type' => 'create', 'register' => 'r1', 'schema' => 'ticket', 'fields' => ['title'], 'defaults' => ['ticketType' => 'request']],
				['id' => 'fileComplaint', 'type' => 'create', 'register' => 'r1', 'schema' => 'ticket', 'fields' => ['title'], 'defaults' => ['ticketType' => 'complaint']],
			]
		);

	}//end twoCreatesOnOneSchema()

	public function testCreateRecordsACreateAuditEntryWithTheNewId(): void {
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
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testCreateRecordsACreateAuditEntryWithTheNewId()

	/**
	 * WMEBV (wmebv-submission-receipts): a successful create fires
	 * SubmissionReceiptService::record() with the subject/tenant scope, the
	 * contributing app id, the action id, and the EXACT whitelisted field map
	 * just persisted (never the raw request body).
	 */
	public function testSuccessfulCreateTriggersReceiptRecordWithWhitelistedMap(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'r1', 'schema' => 'a', 'fields' => ['title']],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('createObject')->willReturn(['id' => 'new']);

		$received = [];
		$receiptService = $this->createMock(SubmissionReceiptService::class);
		$receiptService->expects($this->once())->method('record')->willReturnCallback(
			function (string $subjectRef, string $organisation, string $appId, string $actionId, array $whitelistedData) use (&$received) {
				$received = [
					'subjectRef' => $subjectRef,
					'organisation' => $organisation,
					'appId' => $appId,
					'actionId' => $actionId,
					'whitelistedData' => $whitelistedData,
				];
			}
		);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer, receiptService: $receiptService);
		$response = $controller->create('r1', 'a');

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
	public function testForbiddenCreateNeverTriggersReceiptRecord(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'c1', 'type' => 'create', 'register' => 'other-register', 'schema' => 'other-schema', 'fields' => ['title']],
			]
		);

		$receiptService = $this->createMock(SubmissionReceiptService::class);
		$receiptService->expects($this->never())->method('record');

		$controller = $this->controller(aggregate: $aggregate, receiptService: $receiptService);
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testForbiddenCreateNeverTriggersReceiptRecord()

	/**
	 * A create whose domain write fails (writer returns null → 502) never
	 * fires either follow-on: not `AuditTrailService::record()`
	 * (portal-session-hardening-v2 T09) and not the WMEBV receipt — the
	 * domain write is the authority; nothing was actually persisted, so
	 * nothing should be audited, receipted, or logged.
	 */
	public function testFailedDomainWriteNeverTriggersAuditOrReceiptRecord(): void {
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
		$response = $controller->create('r1', 'a');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());

	}//end testFailedDomainWriteNeverTriggersAuditOrReceiptRecord()

	public function testActionOutsideManifestIs403WithoutOutboundCall(): void {
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

	public function testActionForbiddenNeverRecordsAnAuditEntry(): void {
		// portal-session-hardening-v2 T09: a `forward` is only auditable once
		// the manifest AUTHORISES it — a 403 must never write an entry.
		$aggregate = $this->aggregate(actions: []);

		$auditor = $this->createMock(AuditTrailService::class);
		$auditor->expects($this->never())->method('record');

		$controller = $this->controller(aggregate: $aggregate, auditor: $auditor);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->action('portaliq', 'unknown')->getStatus());

	}//end testActionForbiddenNeverRecordsAnAuditEntry()

	public function testActionSsrfAndTrustGuardsFailClosed(): void {
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

	public function testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes(): void {
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
		$result = $controller->action('portaliq', 'submitAccreditation');

		$this->assertSame(200, $result->getStatus());
		// json_encode([]) is '[]' (an empty PHP array is ambiguous between
		// object/array) — the point being tested is that it is EMPTY, not '{}'.
		$this->assertSame('[]', $captured['body']);

	}//end testActionWithDeclaredFieldsForwardsOnlyThoseFieldsIgnoringSmuggledOnes()

	public function testActionWithoutDeclaredFieldsForwardsTheRawBodyUnchanged(): void {
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

	public function testActionRecordsAForwardAuditEntryBeforeTheOutboundCall(): void {
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

	public function testActionForwardsWithAssertionAndRelaysResponse(): void {
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
		$result = $controller->action('portaliq', 'requestRenewal');

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
	public function testActionWithTypeCreateAndSuccessfulForwardTriggersReceiptRecord(): void {
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

		$received = [];
		$receiptService = $this->createMock(SubmissionReceiptService::class);
		$receiptService->expects($this->once())->method('record')->willReturnCallback(
			function (string $subjectRef, string $organisation, string $appId, string $actionId, array $whitelistedData) use (&$received) {
				$received = compact('subjectRef', 'organisation', 'appId', 'actionId', 'whitelistedData');
			}
		);

		$controller = $this->controller(aggregate: $aggregate, clientService: $clientService, receiptService: $receiptService);
		$result = $controller->action('portaliq', 'submitAccreditation');

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
	public function testActionWithTypeCreateAndFailedForwardNeverTriggersReceiptRecord(): void {
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
		$result = $controller->action('portaliq', 'submitAccreditation');

		$this->assertSame(422, $result->getStatus());

	}//end testActionWithTypeCreateAndFailedForwardNeverTriggersReceiptRecord()

	/**
	 * WMEBV: a non-create endpoint action (no `type` declared, e.g. the demo
	 * health-check forward) never fires the receipt regardless of status.
	 */
	public function testActionWithoutTypeCreateNeverTriggersReceiptRecord(): void {
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

	public function testActionTransportFailureIs502ForwardFailed(): void {
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
		$result = $controller->action('portaliq', 'flaky');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $result->getStatus());
		$this->assertSame(['error' => 'forward_failed'], $result->getData());

	}//end testActionTransportFailureIs502ForwardFailed()

	public function testUnauthenticatedActionIs401(): void {
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
	public function testCollectionParamDisambiguatesSharedSchema(): void {
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

	public function testObjectUnauthenticatedIs401(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->object('r1', 'a', 'id-1')->getStatus());

	}//end testObjectUnauthenticatedIs401()

	public function testObjectOutsideManifestIs403BeforeAnyRead(): void {
		// No collection grants (r1, a) → forbidden, reader never called.
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readObject');

		$controller = $this->controller(aggregate: $this->aggregate(), reader: $reader);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->object('r1', 'a', 'id-1')->getStatus());

	}//end testObjectOutsideManifestIs403BeforeAnyRead()

	public function testObjectBelowTrustThresholdIs403BeforeAnyRead(): void {
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
	public function testObjectNullFromReaderIs404NoOracle(): void {
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'r1', 'schema' => 'a', 'scopeField' => 'subjectRef'],
			]
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturn(null);

		$controller = $this->controller(aggregate: $aggregate, reader: $reader);
		$response = $controller->object('r1', 'a', 'id-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testObjectNullFromReaderIs404NoOracle()

	/**
	 * operate-show-per-case-type REQ-OSC-002: a case of a type the serving
	 * portal hides answers the same 404 as a case that does not exist, and
	 * leaves the list; a collection of another kind is untouched.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function testHiddenCaseTypeIs404(): void {
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'dossiq', 'schema' => 'case', 'scopeField' => 'subjectRef', 'kind' => 'cases', 'caseTypeField' => 'zaaktype'],
				['register' => 'dossiq', 'schema' => 'note', 'scopeField' => 'subjectRef'],
			]
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef, string $id): array => match ($id) {
				'hidden-1' => ['id' => 'hidden-1', 'zaaktype' => 'handhaving'],
				default => ['id' => $id, 'zaaktype' => 'omgevingsvergunning'],
			}
		);
		$reader->method('readCollection')->willReturn([
			['id' => 'shown-1', 'zaaktype' => 'omgevingsvergunning'],
			['id' => 'hidden-1', 'zaaktype' => 'handhaving'],
		]);

		$portals = $this->createMock(PortalResolver::class);
		$portals->method('resolve')->willReturn(null);
		$portals->method('resolveByOrganisation')->willReturn(
			['slug' => 'mijn-org', 'organisation' => 'org-1', 'hiddenCaseTypes' => [['typeId' => 'handhaving']]]
		);
		$controller = $this->controller(aggregate: $aggregate, reader: $reader, caseTypes: new CaseTypeVisibility($portals));

		$hidden = $controller->object('dossiq', 'case', 'hidden-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $hidden->getStatus());
		$this->assertSame(['error' => 'not_found'], $hidden->getData());

		$this->assertSame(Http::STATUS_OK, $controller->object('dossiq', 'case', 'shown-1')->getStatus());

		$list = $controller->collection('dossiq', 'case');
		$this->assertSame(['shown-1'], array_column($list->getData()['objects'], 'id'));

		// Not a case collection: the type field means nothing there.
		$notes = $controller->collection('dossiq', 'note');
		$this->assertSame(['shown-1', 'hidden-1'], array_column($notes->getData()['objects'], 'id'));
	}//end testHiddenCaseTypeIs404()

	public function testObjectReturnsTheSubjectsObjectAndPassesScopeParams(): void {
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'fields' => ['title']],
			]
		);

		$received = [];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $id,
				string $organisation = '',
				string $scopeClaim = '',
				string $contributingApp = '',
				mixed $via = null,
				string $audience = '',
				mixed $fields = null,
			) use (&$received) {
				$received = [
					'register' => $register,
					'schema' => $schema,
					'scopeField' => $scopeField,
					'subjectRef' => $subjectRef,
					'id' => $id,
					'organisation' => $organisation,
					'contributingApp' => $contributingApp,
					'audience' => $audience,
					'fields' => $fields,
				];
				return ['title' => 'Mine', 'id' => 'd-1'];
			}
		);

		$controller = $this->controller(aggregate: $aggregate, reader: $reader);
		$response = $controller->object('portaliq', 'exampleDocument', 'd-1');

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

	public function testUpdateUnauthenticatedIs401(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->update('r1', 'a', 'id-1')->getStatus());

	}//end testUpdateUnauthenticatedIs401()

	public function testUpdateWithoutAnUpdateActionIs403BeforeAnyWrite(): void {
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

	public function testUpdateBelowTrustThresholdIs403BeforeAnyWrite(): void {
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
	public function testUpdateAppliesWhitelistAndReturnsUpdatedObject(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title', 'claims']],
			]
		);

		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
				$received = ['id' => $id, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];
				return ['id' => $id, 'title' => 'X'];
			}
		);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->update('portaliq', 'exampleDocument', 'd-1');

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

	public function testUpdateRecordsAnUpdateAuditEntryWithTheClientId(): void {
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
		$response = $controller->update('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testUpdateRecordsAnUpdateAuditEntryWithTheClientId()

	public function testUpdateOwnershipFailureNeverRecordsAnAuditEntry(): void {
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
		$response = $controller->update('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUpdateOwnershipFailureNeverRecordsAnAuditEntry()

	/**
	 * portal-notifications-dispatch: a SUCCESSFUL update fires
	 * NotificationDispatchService::dispatch() with the `status.changed` rule
	 * key, the matched app id, and the FULL resolved subject (audience is
	 * required to resolve the app's manifest via the registry).
	 */
	public function testSuccessfulUpdateTriggersStatusChangedDispatch(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturn(['id' => 'd-1', 'title' => 'X']);

		$received = [];
		$notificationDispatch = $this->createMock(NotificationDispatchService::class);
		$notificationDispatch->expects($this->once())->method('dispatch')->willReturnCallback(
			function (string $ruleKey, string $appId, array $subject) use (&$received) {
				$received = compact('ruleKey', 'appId', 'subject');
			}
		);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer, notificationDispatch: $notificationDispatch);
		$response = $controller->update('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(NotificationDispatchService::RULE_STATUS_CHANGED, $received['ruleKey']);
		$this->assertSame('portaliq', $received['appId']);
		$this->assertSame(self::SUBJECT, $received['subject']);

	}//end testSuccessfulUpdateTriggersStatusChangedDispatch()

	/**
	 * A failed/unauthorised update (404, before any write) never fires the
	 * dispatch — nothing changed, so nothing should be notified about.
	 */
	public function testFailedUpdateNeverTriggersStatusChangedDispatch(): void {
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
		$response = $controller->update('portaliq', 'exampleDocument', 'd-1');

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
	public function testClaimScopedUpdateResolvesTheScopeValueForOwnership(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'approve', 'type' => 'update', 'register' => 'hrmq', 'schema' => 'timesheet', 'scopeField' => 'costCenter', 'scopeClaim' => 'costCenter', 'fields' => ['status'], 'set' => ['status' => 'approved']],
			]
		);

		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
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
		$response = $controller->update('hrmq', 'timesheet', 't-1');

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
	public function testClaimScopedUpdateWithUnresolvableClaimIs404BeforeAnyWrite(): void {
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
		$response = $controller->update('hrmq', 'timesheet', 't-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testClaimScopedUpdateWithUnresolvableClaimIs404BeforeAnyWrite()

	// -- portal-inbox-v2 (T02/T03): unified inbox + tamper-proof mark-read --

	public function testInboxUnauthenticatedIs401(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->inbox()->getStatus());

	}//end testInboxUnauthenticatedIs401()

	/**
	 * inbox() delegates the aggregate straight to PortalInboxReader — the
	 * merge/sort/provenance logic is PortalInboxReader's own responsibility
	 * (see PortalInboxReaderTest); this test only proves the controller wires
	 * the subject + aggregate through and relays the result.
	 */
	public function testInboxReturnsTheAggregatedMessages(): void {
		$aggregate = $this->aggregate(collections: [['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage']]);

		$messages = [['subject' => 'Hallo', '_source' => ['appId' => 'portaliq', 'label' => 'Portaliq']]];
		$inboxReader = $this->createMock(PortalInboxReader::class);
		$inboxReader->expects($this->once())->method('aggregateInbox')
			->with(self::SUBJECT, $aggregate)
			->willReturn($messages);

		$controller = $this->controller(aggregate: $aggregate, inboxReader: $inboxReader);
		$response = $controller->inbox();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['messages' => $messages], $response->getData());

	}//end testInboxReturnsTheAggregatedMessages()

	public function testMarkReadUnauthenticatedIs401(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->markRead('r1', 'a', 'id-1')->getStatus());

	}//end testMarkReadUnauthenticatedIs401()

	public function testMarkReadOutsideSubjectsManifestIs403IdorGuard(): void {
		// (register, schema) never appears in the subject's own contributions.
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'inbox', 'kind' => 'inbox', 'register' => 'r1', 'schema' => 'a'],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->markRead('r-not-granted', 'schema-not-granted', 'id-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testMarkReadOutsideSubjectsManifestIs403IdorGuard()

	/**
	 * A collection matching (register, schema) that is NOT declared
	 * `kind: inbox` must never be reachable through the mark-read endpoint —
	 * it narrows to inbox collections only, distinct from the plain IDOR guard.
	 */
	public function testMarkReadOnANonInboxCollectionIs403(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'exampleCollection', 'register' => 'portaliq', 'schema' => 'exampleDocument'],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->markRead('portaliq', 'exampleDocument', 'id-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testMarkReadOnANonInboxCollectionIs403()

	public function testMarkReadBelowTrustThresholdIs403BeforeAnyWrite(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage', 'minTrust' => 'substantial'],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->markRead('portaliq', 'portalMessage', 'm-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testMarkReadBelowTrustThresholdIs403BeforeAnyWrite()

	/**
	 * On a subject's own message: the writer receives a LITERAL `['read' => true]`
	 * payload — never anything derived from the request body — so no other
	 * field can ever be written through this endpoint.
	 */
	public function testMarkReadSetsOnlyTheReadFieldOnTheSubjectsOwnMessage(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage'],
			]
		);

		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
				$received = ['id' => $id, 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'data' => $data];
				return ['id' => $id, 'subject' => 'Hallo', 'read' => true];
			}
		);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->markRead('portaliq', 'portalMessage', 'm-1');

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
	 * portaliq#702: a collection that names its own read date gets the current
	 * time in that one field, never `read`, never anything from the body.
	 *
	 * @spec openspec/changes/inbox-reads-each-apps-message-fields/specs/supplier-portal/spec.md#requirement-mark-read-writes-the-collections-own-read-field-req-imf-002
	 */
	public function testMarkReadWritesTheDeclaredReadAtField(): void {
		$aggregate = $this->aggregate(
			collections: [
				[
					'id' => 'berichten',
					'kind' => 'inbox',
					'register' => 'dossiq',
					'schema' => 'portaalBericht',
					'scopeField' => 'recipientRef',
					'messageFields' => ['body' => 'content', 'readAt' => 'readByRecipientAt'],
				],
			]
		);

		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
				$received = ['scopeField' => $scopeField, 'data' => $data];
				return ['id' => $id];
			}
		);

		$before = time();
		$response = $this->controller(aggregate: $aggregate, writer: $writer)->markRead('dossiq', 'portaalBericht', 'b-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['readByRecipientAt'], array_keys($received['data']));
		$this->assertSame('recipientRef', $received['scopeField']);
		$written = strtotime($received['data']['readByRecipientAt']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $received['data']['readByRecipientAt']);
		$this->assertGreaterThanOrEqual($before, $written);
		$this->assertLessThanOrEqual(time(), $written);

	}//end testMarkReadWritesTheDeclaredReadAtField()

	/**
	 * A foreign-owned or non-existent message id: the writer's own ownership
	 * re-verification returns null (no write happened, identical to every
	 * other scoped write), and the controller answers the SAME 404 — no
	 * existence oracle.
	 */
	public function testMarkReadOnAForeignOrAbsentMessageIs404WithNoWrite(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'inbox', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage'],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')->willReturn(null);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->markRead('portaliq', 'portalMessage', 'not-mine');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testMarkReadOnAForeignOrAbsentMessageIs404WithNoWrite()

	/**
	 * A declared scopeClaim that cannot resolve on the inbox collection →
	 * 404, no write — the SAME fail-closed posture as a claim-scoped update().
	 */
	public function testMarkReadWithUnresolvableClaimIs404BeforeAnyWrite(): void {
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
		$response = $controller->markRead('portaliq', 'portalMessage', 'm-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testMarkReadWithUnresolvableClaimIs404BeforeAnyWrite()

	/**
	 * A resident marks one of portaliq's own notices read although no
	 * contribution declares an inbox over `portalMessage`: the write is scoped
	 * on `subjectRef` with the bearer's own reference, and still sets `read`
	 * only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-shows-portal-messages/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 */
	public function testMarkReadReachesTheResidentsOwnPortalMessageWithoutADeclaredInbox(): void {
		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use (&$received) {
				$received = [$register, $schema, $scopeField, $subjectRef, $organisation, $id, $data];
				return ['id' => $id, 'read' => true];
			}
		);

		$controller = $this->controller(aggregate: $this->aggregate(collections: []), writer: $writer, params: ['collection' => 'portalMessages']);
		$response = $controller->markRead('portaliq', 'portalMessage', 'm-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['portaliq', 'portalMessage', 'subjectRef', 's1', 'org-1', 'm-1', ['read' => true]], $received);
	}//end testMarkReadReachesTheResidentsOwnPortalMessageWithoutADeclaredInbox()

	/**
	 * The fallback opens portaliq's own messages only: another schema, or a
	 * collection id that is not the built-in one, stays 403 with no write.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbox-shows-portal-messages/specs/portal-notifications-and-preferences/spec.md#requirement-portaliqs-own-notices-reach-the-residents-inbox-req-nap-009
	 */
	public function testMarkReadFallbackOpensNothingButPortaliqsOwnMessages(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->never())->method('updateObject');

		$controller = $this->controller(aggregate: $this->aggregate(collections: []), writer: $writer, params: ['collection' => 'somethingElse']);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->markRead('portaliq', 'portalMessage', 'm-1')->getStatus());

		$controller = $this->controller(aggregate: $this->aggregate(collections: []), writer: $writer);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->markRead('portaliq', 'portalAccount', 'a-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->markRead('other', 'portalMessage', 'm-1')->getStatus());
	}//end testMarkReadFallbackOpensNothingButPortaliqsOwnMessages()

	/**
	 * A resident deletes one of portaliq's own notices: the delete is scoped
	 * on `subjectRef` with the bearer's own reference and tenant.
	 *
	 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
	 */
	public function testDeleteMessageRemovesTheResidentsOwnNotice(): void {
		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('deleteObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id) use (&$received): bool {
				$received = [$register, $schema, $scopeField, $subjectRef, $organisation, $id];
				return true;
			}
		);

		$controller = $this->controller(aggregate: $this->aggregate(collections: []), writer: $writer, params: ['collection' => 'portalMessages']);
		$response = $controller->deleteMessage('portaliq', 'portalMessage', 'm-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deleted' => true], $response->getData());
		$this->assertSame(['portaliq', 'portalMessage', 'subjectRef', 's1', 'org-1', 'm-1'], $received);
	}//end testDeleteMessageRemovesTheResidentsOwnNotice()

	/**
	 * Another resident's message, or one that does not exist, is the same
	 * 404; nothing is deleted. Without a bearer it is 401.
	 *
	 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
	 */
	public function testDeleteMessageOfAnotherResidentIs404(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('deleteObject')->willReturn(false);

		$controller = $this->controller(aggregate: $this->aggregate(collections: []), writer: $writer, params: ['collection' => 'portalMessages']);
		$response = $controller->deleteMessage('portaliq', 'portalMessage', 'not-mine');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

		$anonymous = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->deleteMessage('portaliq', 'portalMessage', 'm-1')->getStatus());
	}//end testDeleteMessageOfAnotherResidentIs404()

	/**
	 * An app's inbox is the app's record: a resident deletes from it only
	 * when the collection declares `deletable: true`. A collection that is
	 * no inbox, or that asks more trust than the session has, stays 403.
	 *
	 * @spec openspec/changes/inbox-delete-own-messages/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
	 */
	public function testDeleteMessageFromAnAppsInboxNeedsItsConsent(): void {
		$aggregate = $this->aggregate(
			collections: [
				['id' => 'berichten', 'kind' => 'inbox', 'register' => 'dossiq', 'schema' => 'portaalBericht', 'scopeField' => 'recipientRef'],
				['id' => 'meldingen', 'kind' => 'inbox', 'register' => 'learniq', 'schema' => 'notice', 'scopeField' => 'guardianRef', 'deletable' => true],
				['id' => 'strict', 'kind' => 'inbox', 'register' => 'learniq', 'schema' => 'secret', 'deletable' => true, 'minTrust' => 'high'],
				['id' => 'docs', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'deletable' => true],
			]
		);

		$received = [];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('deleteObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField) use (&$received): bool {
				$received = [$register, $schema, $scopeField];
				return true;
			}
		);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->deleteMessage('dossiq', 'portaalBericht', 'b-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->deleteMessage('learniq', 'secret', 'x-1')->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->deleteMessage('portaliq', 'exampleDocument', 'd-1')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->deleteMessage('learniq', 'notice', 'n-1')->getStatus());
		$this->assertSame(['learniq', 'notice', 'guardianRef'], $received);
	}//end testDeleteMessageFromAnAppsInboxNeedsItsConsent()

	public function testUploadRequiresTheCollectionToOptIntoFileUploads(): void {
		// The collection does NOT declare filesUpload → 403, no read, no attach.
		$aggregate = $this->aggregate(
			collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'listable' => true]]
		);

		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->expects($this->never())->method('attachFile');

		$controller = $this->controller(aggregate: $aggregate, fileWriter: $fileWriter);
		$response = $controller->uploadFile('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testUploadRequiresTheCollectionToOptIntoFileUploads()

	public function testUploadForeignOrAbsentObjectIs404BeforeAnyAttach(): void {
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
		$response = $controller->uploadFile('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testUploadForeignOrAbsentObjectIs404BeforeAnyAttach()

	public function testUploadAttachesTheFileToAnOwnedObject(): void {
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

		$captured = [];
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
			$this->forwarder(request: $request, session: $session),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class)
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
	public function testUpdateOwnershipFailureFromWriterIs404(): void {
		$aggregate = $this->aggregate(
			actions: [
				['id' => 'u1', 'type' => 'update', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'fields' => ['title']],
			]
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturn(null);

		$controller = $this->controller(aggregate: $aggregate, writer: $writer);
		$response = $controller->update('portaliq', 'exampleDocument', 'not-mine');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testUpdateOwnershipFailureFromWriterIs404()

	/**
	 * portal-document-download: the object() response is enriched with a safe
	 * `_files` listing only when the matched collection opts in.
	 */
	public function testObjectAttachesFilesListingOnlyWhenCollectionOptsIn(): void {
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
		$response = $controller->object('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 7, 'name' => 'besluit.pdf', 'size' => 10]], $response->getData()['object']['_files']);

	}//end testObjectAttachesFilesListingOnlyWhenCollectionOptsIn()

	public function testObjectNeverAttachesFilesListingWhenCollectionDoesNotOptIn(): void {
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
		$response = $controller->object('portaliq', 'exampleDocument', 'd-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertArrayNotHasKey('_files', $response->getData()['object']);

	}//end testObjectNeverAttachesFilesListingWhenCollectionDoesNotOptIn()

	public function testDownloadUnauthenticatedIs401(): void {
		$controller = $this->controller(aggregate: $this->aggregate(), subject: null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->downloadFile('r1', 'a', 'id-1', 'f-1')->getStatus());

	}//end testDownloadUnauthenticatedIs401()

	public function testDownloadOutsideManifestIs403BeforeAnyRead(): void {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readObject');

		$fileReader = $this->createMock(PortalFileReader::class);
		$fileReader->expects($this->never())->method('streamFile');

		$controller = $this->controller(aggregate: $this->aggregate(), reader: $reader, fileReader: $fileReader);
		$response = $controller->downloadFile('r1', 'a', 'id-1', 'f-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testDownloadOutsideManifestIs403BeforeAnyRead()

	public function testDownloadBelowTrustThresholdIs403BeforeAnyRead(): void {
		$aggregate = $this->aggregate(
			collections: [
				['register' => 'r1', 'schema' => 'a', 'minTrust' => 'high', 'filesDownload' => true],
			]
		);

		$fileReader = $this->createMock(PortalFileReader::class);
		$fileReader->expects($this->never())->method('streamFile');

		$controller = $this->controller(aggregate: $aggregate, fileReader: $fileReader);
		$response = $controller->downloadFile('r1', 'a', 'id-1', 'f-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testDownloadBelowTrustThresholdIs403BeforeAnyRead()

	/**
	 * A collection that has NOT declared `filesDownload: true` refuses BEFORE
	 * any OpenRegister read, with the identical 404 body every other refusal
	 * uses (no existence oracle).
	 */
	public function testDownloadRequiresTheCollectionToOptIntoFileDownloads(): void {
		$aggregate = $this->aggregate(
			collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef']]
		);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->expects($this->never())->method('readObject');

		$fileReader = $this->createMock(PortalFileReader::class);
		$fileReader->expects($this->never())->method('streamFile');

		$controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader);
		$response = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'f-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testDownloadRequiresTheCollectionToOptIntoFileDownloads()

	/**
	 * A foreign-owned or non-existent object is refused with the IDENTICAL 404
	 * BEFORE the file layer is ever touched.
	 */
	public function testDownloadForeignOrAbsentObjectIs404BeforeAnyStream(): void {
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
		$response = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'f-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testDownloadForeignOrAbsentObjectIs404BeforeAnyStream()

	/**
	 * A `fileId` that does not resolve (non-existent, or foreign to the owned
	 * object's folder) is the SAME 404 as the two refusals above — no oracle.
	 */
	public function testDownloadNonExistentFileIs404IdenticalToOtherRefusals(): void {
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
		$response = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'not-mine');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testDownloadNonExistentFileIs404IdenticalToOtherRefusals()

	/**
	 * On success: ownership is re-verified BEFORE the file layer runs, the
	 * resolved stream is returned to the client, and the audit hook is invoked
	 * with the verb `download` and the target register/schema/id.
	 */
	public function testDownloadStreamsOwnedFileAndInvokesAuditHookOnSuccess(): void {
		$aggregate = $this->aggregate(
			collections: [['id' => 'c1', 'register' => 'portaliq', 'schema' => 'exampleDocument', 'scopeField' => 'subjectRef', 'filesDownload' => true]]
		);

		$received = [];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $id,
				string $organisation = '',
				string $scopeClaim = '',
				string $contributingApp = '',
				mixed $via = null,
				string $audience = '',
				mixed $fields = null,
			) use (&$received) {
				$received = ['register' => $register, 'schema' => $schema, 'scopeField' => $scopeField, 'subjectRef' => $subjectRef, 'id' => $id];
				return ['id' => 'd-1', 'title' => 'Mine'];
			}
		);

		$expectedStream = $this->createMock(StreamResponse::class);
		$fileReader = $this->createMock(PortalFileReader::class);
		$fileReader->expects($this->once())->method('streamFile')->with('portaliq', 'exampleDocument', 'd-1', 'f-1')->willReturn($expectedStream);

		$auditHook = $this->createMock(PortalAuditHook::class);
		$auditHook->expects($this->once())->method('download')->with('s1', 'org-1', 'portaliq', 'exampleDocument', 'd-1');

		$controller = $this->controller(aggregate: $aggregate, reader: $reader, fileReader: $fileReader, auditHook: $auditHook);
		$response = $controller->downloadFile('portaliq', 'exampleDocument', 'd-1', 'f-1');

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
	private function controllerWithCollectionParam(array $aggregate, string $collectionId, PortalObjectReader $reader): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', 'Bearer client-session-token']]);
		$request->method('getParam')->willReturnCallback(
			fn (string $key, $default = null) => ($key === 'collection' ? $collectionId : $default)
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
			$this->forwarder(request: $request, session: $session),
			$this->createMock(AuditTrailService::class),
			$this->createMock(SubmissionReceiptService::class),
			$this->createMock(NotificationDispatchService::class),
			$this->createMock(LoggerInterface::class)
		);

	}//end controllerWithCollectionParam()

	/**
	 * A reader mock whose readCollection() records every received argument
	 * into the given array (by reference) and returns no rows.
	 */
	private function readerCapturing(array &$received): PortalObjectReader {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $organisation = '',
				int $limit = 200,
				string $scopeClaim = '',
				string $contributingApp = '',
				mixed $via = null,
				string $audience = '',
				mixed $fields = null,
			) use (&$received) {
				$received = [
					'register' => $register,
					'schema' => $schema,
					'scopeField' => $scopeField,
					'subjectRef' => $subjectRef,
					'organisation' => $organisation,
					'limit' => $limit,
					'scopeClaim' => $scopeClaim,
					'contributingApp' => $contributingApp,
					'via' => $via,
					'audience' => $audience,
					'fields' => $fields,
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
		?array $subject = self::SUBJECT,
		?PortalObjectReader $reader = null,
		?PortalObjectWriter $writer = null,
		?IClientService $clientService = null,
		?PortalFileWriter $fileWriter = null,
		?PortalFileReader $fileReader = null,
		?PortalAuditHook $auditHook = null,
		?PortalInboxReader $inboxReader = null,
		?AuditTrailService $auditor = null,
		?SubmissionReceiptService $receiptService = null,
		?NotificationDispatchService $notificationDispatch = null,
		?array $anonymousAggregate = null,
		?PortalSchemaReader $schemaReader = null,
		?CaseTypeVisibility $caseTypes = null,
		array $params = [],
		?PortalResolver $portals = null,
	): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', 'Bearer client-session-token'], ['X-Portaliq-Portal', '']]);
		$request->method('getParam')->willReturnCallback(
			function (string $key, $default = null) use ($params) {
				$params = $params + [
					'title' => 'X',
					'claims' => ['portaliq' => ['exampleContactId' => 'HACKED']],
				];
				return ($params[$key] ?? $default);
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
			fn (string $path) => 'https://cloud.example' . $path
		);

		// Default reader: resolveScopeValue returns the subject's subjectRef (the
		// real no-scopeClaim behaviour) so update()'s ownership resolution passes.
		$defaultReader = $this->createMock(PortalObjectReader::class);
		$defaultReader->method('resolveScopeValue')->willReturn((string)($subject['subjectRef'] ?? ''));

		return new ContributionController(
			$request,
			$registry,
			$session,
			($reader ?? $defaultReader),
			($writer ?? $this->createMock(PortalObjectWriter::class)),
			($fileWriter ?? $this->createMock(PortalFileWriter::class)),
			($fileReader ?? $this->createMock(PortalFileReader::class)),
			($schemaReader ?? $this->createMock(PortalSchemaReader::class)),
			($inboxReader ?? $this->createMock(PortalInboxReader::class)),
			($auditHook ?? $this->createMock(PortalAuditHook::class)),
			$this->forwarder(
				request: $request,
				session: $session,
				clientService: $clientService,
				urlGenerator: $urlGenerator
			),
			($auditor ?? $this->createMock(AuditTrailService::class)),
			($receiptService ?? $this->createMock(SubmissionReceiptService::class)),
			($notificationDispatch ?? $this->createMock(NotificationDispatchService::class)),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			$caseTypes,
			portals: $portals
		);

	}//end controller()

	/**
	 * `GET /portal/api/schema/{schema}` (`contribution#schema`) — an anonymous
	 * caller is refused before any schema is read.
	 */
	public function testSchemaRefusesAnAnonymousCallerWithoutReadingASchema(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->expects($this->never())->method('readSchema');

		$controller = $this->controller(
			aggregate: $this->aggregate(),
			subject: null,
			schemaReader: $schemaReader
		);

		$response = $controller->schema('exampleDocument');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testSchemaRefusesAnAnonymousCallerWithoutReadingASchema()

	/**
	 * A subject may only introspect a schema their OWN manifest references.
	 * A schema outside the aggregate is 403 — and, critically, the reader is
	 * never called, so the refusal happens BEFORE OpenRegister is touched
	 * (the reader deliberately runs `_rbac: false`, so a call that reached it
	 * would have succeeded).
	 */
	public function testSchemaRefusesASchemaTheManifestDoesNotReference(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->expects($this->never())->method('readSchema');

		$controller = $this->controller(
			aggregate: $this->aggregate(collections: [['register' => 'portaliq', 'schema' => 'exampleDocument']]),
			schemaReader: $schemaReader
		);

		$response = $controller->schema('secretPersonnelFile');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['error' => 'forbidden'], $response->getData());

	}//end testSchemaRefusesASchemaTheManifestDoesNotReference()

	/**
	 * A schema referenced by a manifest COLLECTION is served as-is.
	 */
	public function testSchemaServesADefinitionReferencedByACollection(): void {
		$definition = ['title' => 'exampleDocument', 'properties' => ['name' => ['type' => 'string']]];

		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->expects($this->once())
			->method('readSchema')
			->with('exampleDocument')
			->willReturn($definition);

		$controller = $this->controller(
			aggregate: $this->aggregate(collections: [['register' => 'portaliq', 'schema' => 'exampleDocument']]),
			schemaReader: $schemaReader
		);

		$response = $controller->schema('exampleDocument');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($definition, $response->getData());

	}//end testSchemaServesADefinitionReferencedByACollection()

	/**
	 * An ACTION reference authorises introspection too — a subject that may
	 * submit a form must be able to read the schema that form is built from.
	 */
	public function testSchemaServesADefinitionReferencedByAnAction(): void {
		$definition = ['title' => 'melding', 'properties' => []];

		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn($definition);

		$controller = $this->controller(
			aggregate: $this->aggregate(actions: [['id' => 'openIntake', 'type' => 'create', 'schema' => 'melding']]),
			schemaReader: $schemaReader
		);

		$response = $controller->schema('melding');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($definition, $response->getData());

	}//end testSchemaServesADefinitionReferencedByAnAction()

	/**
	 * An authorised schema that OpenRegister cannot resolve is 404 — not a 200
	 * carrying an empty body, which the schema-driven frontend would render as
	 * a form with no fields.
	 */
	public function testSchemaReturnsNotFoundWhenTheReaderResolvesNothing(): void {
		$schemaReader = $this->createMock(PortalSchemaReader::class);
		$schemaReader->method('readSchema')->willReturn(null);

		$controller = $this->controller(
			aggregate: $this->aggregate(collections: [['register' => 'portaliq', 'schema' => 'exampleDocument']]),
			schemaReader: $schemaReader
		);

		$response = $controller->schema('exampleDocument');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'not_found'], $response->getData());

	}//end testSchemaReturnsNotFoundWhenTheReaderResolvesNothing()

	/**
	 * The real PortalActionForwarder wired to the SAME request/session/client
	 * mocks the controller under test uses — the forward's transport behaviour
	 * (signed assertion header, instance-local URL, method dispatch, 502 on a
	 * transport throw) is still exercised end-to-end through action().
	 */
	private function forwarder(
		IRequest $request,
		PortalSessionService $session,
		?IClientService $clientService = null,
		?IURLGenerator $urlGenerator = null,
	): PortalActionForwarder {
		if ($urlGenerator === null) {
			$urlGenerator = $this->createMock(IURLGenerator::class);
			$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
				fn (string $path) => 'https://cloud.example' . $path
			);
		}

		return new PortalActionForwarder(
			$request,
			new InstanceLoopback(
				($clientService ?? $this->createMock(IClientService::class)),
				$urlGenerator,
				$this->createMock(InternalBaseUrl::class),
				$this->createMock(LoggerInterface::class)
			),
			$session
		);

	}//end forwarder()

	/**
	 * A one-contribution aggregate shaped like the registry's output.
	 */
	private function aggregate(array $collections = [], array $actions = []): array {
		return [
			'audience' => 'supplier',
			'organisation' => 'org-1',
			'contributions' => [
				[
					'app' => 'portaliq',
					'label' => 'Test',
					'collections' => $collections,
					'actions' => $actions,
				],
			],
		];

	}//end aggregate()

}//end class
