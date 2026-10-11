<?php

/**
 * Tests for the three acts a citizen may perform on their own case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\CitizenDocumentUpload;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\CitizenCaseController;
use OCA\Portaliq\Event\PortalClientWriteEvent;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\CitizenWritableSetResolver;
use OCA\Portaliq\Service\CitizenWriteRecorder;
use OCA\Portaliq\Service\CitizenCaseDocuments;
use OCA\Portaliq\Service\CitizenWriteThrottle;
use OCA\Portaliq\Service\MandatedCaseReader;
use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalCaseDocumentReader;
use OCP\AppFramework\Http\StreamResponse;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The probes here are deliberately taken with the LEAST privileged principal
 * that should be refused. Every refusal is asserted to have written nothing,
 * because a refusal that still writes is the failure this whole change exists
 * to prevent.
 *
 * dossiq's declaration does not exist yet, so the case type is a fake. What is
 * under test is the contract the portal reads it through.
 *
 * @covers \OCA\Portaliq\Controller\CitizenCaseController
 * @uses   \OCA\Portaliq\Contribution\CitizenDocumentUpload
 * @uses   \OCA\Portaliq\Contribution\CitizenWriteActionFinder
 * @uses   \OCA\Portaliq\Event\PortalClientWriteEvent
 * @uses   \OCA\Portaliq\Event\PortalClientWithdrawalEvent
 * @uses   \OCA\Portaliq\Service\CitizenWritableSetResolver
 * @uses   \OCA\Portaliq\Service\CitizenWriteRecorder
 * @uses   \OCA\Portaliq\Service\CitizenWriteThrottle
 * @uses   \OCA\Portaliq\Service\PortalSessionService
 * @uses   \OCA\Portaliq\Contribution\TimelineProviderMethod
 * @uses   \OCA\Portaliq\Service\Branch\BranchNumber
 * @uses   \OCA\Portaliq\Service\Branch\PortalBranchScope
 * @uses   \OCA\Portaliq\Service\CitizenCaseDocuments
 * @uses   \OCA\Portaliq\Service\PortalCaseDocumentReader
 * @uses   \OCA\Portaliq\Service\MandatedCaseReader
 * @uses   \OCA\Portaliq\Service\CitizenCaseProjection
 * @uses   \OCA\Portaliq\Service\PortalFieldProjector
 * @uses   \OCA\Portaliq\Service\CaseRowMarker
 * @uses   \OCA\Portaliq\Service\CaseStatusView
 *
 * @spec openspec/changes/what-the-citizen-may-write-on-their-own-case/specs/citizen-writes-on-their-own-case/spec.md
 */
class CitizenCaseControllerTest extends TestCase {
	private const SUBJECT = [
		'subjectRef' => 'bsn-hash-1',
		'audience' => 'client',
		'organisation' => 'gemeente-1',
		'trust' => 'high',
		'jti' => 'jti-1',
	];

	private const OTHER_CASE_ID = 'zaak-2';

	private const CASE_ID = 'zaak-1';

	/**
	 * What the case app publishes on zaak-1: a decision and a letter, with
	 * the file references only the server may see.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const PUBLISHED = [
		['id' => 'brief-1', 'title' => 'Ontvangstbevestiging', 'kind' => 'document', 'date' => '2026-08-01', 'file' => ['register' => 'zaken', 'schema' => 'document', 'id' => 'doc-obj-1', 'fileId' => '71']],
		['id' => 'besluit-1', 'title' => 'Besluit op uw aanvraag', 'kind' => 'decision', 'date' => '2026-09-01', 'file' => ['register' => 'zaken', 'schema' => 'document', 'id' => 'doc-obj-2', 'fileId' => '72'], 'mimeType' => 'application/pdf', 'size' => 2048],
	];

	/**
	 * The case collection, declaring the documents method.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const WITH_DOCUMENTS = [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak', 'documents' => ['label' => 'Stukken', 'provider' => 'caseDocuments']]];

	/**
	 * Files streamed: [register, schema, id, fileId].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $streamed = [];

	/**
	 * Downloads audited: [subjectRef, register, schema, id].
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $downloads = [];

	/**
	 * The tags each attached file got.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $attachedTags = [];

	/**
	 * Calls the fake writer recorded, so a refusal can be shown to have
	 * written nothing.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Events the fake dispatcher saw.
	 *
	 * @var array<int, Event>
	 */
	private array $events = [];

	/**
	 * The scope values the reader was asked to read with.
	 *
	 * @var array<int, string>
	 */
	private array $readScopes = [];

	/**
	 * Files the fake file writer attached, by name.
	 *
	 * @var array<int, string>
	 */
	private array $attached = [];

	protected function setUp(): void {
		parent::setUp();
		$this->writes = [];
		$this->events = [];
		$this->readScopes = [];
		$this->attached = [];
	}//end setUp()

	/**
	 * No bearer: 401 on all three acts, and nothing is written or raised.
	 */
	public function testWithoutASessionEveryActIsRefusedAndNothingIsWritten(): void {
		$controller = $this->controller(subject: null);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->show('zaken', 'zaak', self::CASE_ID)->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->amend('zaken', 'zaak', self::CASE_ID)->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->addDocument('zaken', 'zaak', self::CASE_ID)->getStatus());
		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->events);
	}//end testWithoutASessionEveryActIsRefusedAndNothingIsWritten()

	/**
	 * cases-my-cases-page REQ-CMC-004: a case listed under a mandate opens on
	 * the case screen under that mandate, read-only, naming the mandate. The
	 * same case without the mandate, and any write under it, stays "not
	 * yours", and nothing is written.
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-you-choose-whom-you-act-for-req-cmc-004
	 */
	public function testACaseListedUnderAMandateOpensReadOnlyUnderIt(): void {
		$company = ['id' => 'zaak-9', 'omschrijving' => 'een bedrijfspand', 'status' => 'ontvangen', '_mandate' => ['id' => 'mandate-1', 'label' => 'Bakkerij Jansen BV']];
		$mandated = $this->getMockBuilder(MandatedCaseReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['read'])
			->getMock();
		$mandated->method('read')->willReturnCallback(
			static fn (array $subject, string $mandateId, string $register, string $schema, string $id): ?array => (
				$mandateId === 'mandate-1' && $register === 'zaken' && $schema === 'zaak' && $id === 'zaak-9' ? $company : null
			)
		);

		$under = $this->controller(params: ['mandate' => 'mandate-1'], mandatedCases: $mandated);
		$response = $under->show('zaken', 'zaak', 'zaak-9');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('een bedrijfspand', $data['case']['omschrijving']);
		$this->assertSame('Bakkerij Jansen BV', $data['mandate']['label']);
		$this->assertFalse($data['writableSet']['window']['open']);
		$this->assertFalse($data['writableSet']['documents']['open']);
		$this->assertSame('You are viewing this case on behalf of %s. It cannot be changed here.', $data['writableSet']['window']['reason']);
		$this->assertFalse($data['withdrawal']['declared']);
		$this->assertSame([], $data['documents']);

		// Without naming the mandate, the company's case is not theirs.
		$own = $this->controller(mandatedCases: $mandated);
		$this->assertSame('case-not-yours', $own->show('zaken', 'zaak', 'zaak-9')->getData()['error']);

		// A write under the mandate is refused the same way, and writes nothing.
		$write = $this->controller(fields: ['omschrijving' => 'iets anders'], params: ['mandate' => 'mandate-1'], mandatedCases: $mandated);
		$this->assertSame('case-not-yours', $write->amend('zaken', 'zaak', 'zaak-9')->getData()['error']);
		$this->assertSame([], $this->writes);
	}//end testACaseListedUnderAMandateOpensReadOnlyUnderIt()

	/**
	 * A correction lands without a phone call: the case carries the new answer
	 * and a record naming the citizen, and the rule fires once.
	 */
	public function testACorrectionLandsWithTheIdentityRecorded(): void {
		$controller = $this->controller(fields: ['omschrijving' => 'dakkapel aan de achterzijde']);

		$response = $controller->amend('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->writes);
		$written = $this->writes[0]['data'];
		$this->assertSame('dakkapel aan de achterzijde', $written['omschrijving']);

		$record = $written['portalWrites'][0];
		$this->assertSame('amendment', $record['act']);
		$this->assertSame('bsn-hash-1', $record['identity']['subjectRef']);
		$this->assertSame('client', $record['identity']['audience']);
		$this->assertSame('amend-request', $record['mandate']['action']);
		// Both answers survive, so a case worker sees what changed.
		$this->assertSame('een dakkapel', $record['changes']['omschrijving']['from']);
		$this->assertSame('dakkapel aan de achterzijde', $record['changes']['omschrijving']['to']);

		$this->assertCount(1, $this->events);
		$event = $this->events[0];
		$this->assertInstanceOf(PortalClientWriteEvent::class, $event);
		$this->assertSame(self::CASE_ID, $event->getCaseId());
		$this->assertSame(['omschrijving'], $event->getFields());
	}//end testACorrectionLandsWithTheIdentityRecorded()

	/**
	 * The same write, with the window closed: refused with a status and the
	 * case type's own sentence, and nothing is written or raised.
	 */
	public function testTheSameWriteOutsideTheWindowIsRefusedWithASentence(): void {
		$controller = $this->controller(
			fields: ['omschrijving' => 'te laat'],
			caseType: $this->caseType(amendmentStatuses: ['concept'])
		);

		$response = $controller->amend('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('amendment-window-closed', $response->getData()['error']);
		$this->assertSame('De aanvraag is in behandeling genomen.', $response->getData()['message']);
		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->events);
	}//end testTheSameWriteOutsideTheWindowIsRefusedWithASentence()

	/**
	 * A write naming a field the case type never flagged is refused, with the
	 * sentence, and nothing is written.
	 */
	public function testAWriteToAnUndeclaredFieldIsRefused(): void {
		$controller = $this->controller(fields: ['status' => 'afgehandeld']);

		$response = $controller->amend('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('field-not-writable', $response->getData()['error']);
		$this->assertNotSame('', $response->getData()['message']);
		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->events);
	}//end testAWriteToAnUndeclaredFieldIsRefused()

	/**
	 * A citizen cannot write on a case that is not theirs. The refusal is the
	 * same one an unknown case number gets, so the portal never confirms that
	 * someone else's case exists.
	 */
	public function testACaseThatIsNotTheirsIsRefusedAndSoIsAnUnknownOne(): void {
		$controller = $this->controller(fields: ['omschrijving' => 'niet van mij']);

		$foreign = $controller->amend('zaken', 'zaak', self::OTHER_CASE_ID);
		$unknown = $controller->amend('zaken', 'zaak', 'zaak-bestaat-niet');

		$this->assertSame(Http::STATUS_FORBIDDEN, $foreign->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $unknown->getStatus());
		$this->assertSame($foreign->getData(), $unknown->getData());
		$this->assertSame('case-not-yours', $foreign->getData()['error']);
		$this->assertSame([], $this->writes);
	}//end testACaseThatIsNotTheirsIsRefusedAndSoIsAnUnknownOne()

	/**
	 * The count of cases a citizen sees is theirs only: of the two cases in
	 * the fixture, exactly one is readable, and the scope the reader is asked
	 * for is always the SESSION's, never anything the request supplied.
	 */
	public function testTheCountOfCasesTheySeeIsTheirsOnly(): void {
		$controller = $this->controller(fields: ['subjectRef' => 'bsn-hash-2']);

		$visible = 0;
		foreach ([self::CASE_ID, self::OTHER_CASE_ID] as $caseId) {
			if ($controller->show('zaken', 'zaak', $caseId)->getStatus() === Http::STATUS_OK) {
				$visible++;
			}
		}

		$this->assertSame(1, $visible);
		$this->assertSame(['bsn-hash-1', 'bsn-hash-1'], $this->readScopes);
	}//end testTheCountOfCasesTheySeeIsTheirsOnly()

	/**
	 * An aanvulling reaches the case, recorded as the citizen's.
	 */
	public function testAnAanvullingReachesTheCaseAsTheirs(): void {
		$controller = $this->controller(upload: ['name' => 'aanvulling.pdf', 'content' => '%PDF']);

		$response = $controller->addDocument('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['aanvulling.pdf'], $this->attached);
		$this->assertCount(1, $this->events);
		$this->assertSame(PortalClientWriteEvent::ACT_DOCUMENT, $this->events[0]->getAct());
	}//end testAnAanvullingReachesTheCaseAsTheirs()

	/**
	 * Nothing already on the case is replaced: a document of the same name the
	 * municipality added keeps its name, and the citizen's gets another.
	 */
	public function testADocumentOfTheSameNameDoesNotOverwrite(): void {
		$controller = $this->controller(
			upload: ['name' => 'besluit.pdf', 'content' => '%PDF'],
			existingFiles: [['name' => 'besluit.pdf'], ['name' => 'besluit-2.pdf']]
		);

		$controller->addDocument('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(['besluit-3.pdf'], $this->attached);
	}//end testADocumentOfTheSameNameDoesNotOverwrite()

	/**
	 * A case whose document window is closed refuses the upload with the case
	 * type's sentence, and nothing is attached.
	 */
	public function testAClosedDocumentWindowRefusesTheUpload(): void {
		$controller = $this->controller(
			upload: ['name' => 'aanvulling.pdf', 'content' => '%PDF'],
			caseType: $this->caseType(documentStatuses: ['concept'])
		);

		$response = $controller->addDocument('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('documents-closed', $response->getData()['error']);
		$this->assertSame([], $this->attached);
	}//end testAClosedDocumentWindowRefusesTheUpload()

	/**
	 * A contribution that declares no citizen write surface refuses all three
	 * acts, whatever else its update action permits.
	 */
	public function testWithoutADeclarationThereIsNoCitizenWriteSurface(): void {
		$action = $this->action();
		unset($action['citizenWrite']);
		$controller = $this->controller(fields: ['omschrijving' => 'x'], action: $action);

		$response = $controller->amend('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('portal-writes-not-declared', $response->getData()['error']);
		$this->assertSame([], $this->writes);
	}//end testWithoutADeclarationThereIsNoCitizenWriteSurface()

	/**
	 * Past the throttle the surface refuses with 429 and a sentence, and
	 * nothing is written.
	 */
	public function testPastTheThrottleTheWriteIsRefused(): void {
		$controller = $this->controller(fields: ['omschrijving' => 'nogmaals'], throttleOpen: false);

		$response = $controller->amend('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame('too-many-writes', $response->getData()['error']);
		$this->assertSame([], $this->writes);
	}//end testPastTheThrottleTheWriteIsRefused()

	/**
	 * The citizen reads the label the case app supplied, unchanged, and the
	 * portal invents nothing when there is none.
	 */
	public function testThePublicStatusLabelIsRenderedUnchanged(): void {
		$response = $this->controller()->show('zaken', 'zaak', self::CASE_ID);

		$status = $response->getData()['writableSet']['status'];
		$this->assertSame('Wij hebben uw aanvraag ontvangen', $status['label']);
		$this->assertSame('U hoort binnen acht weken van ons.', $status['description']);

		$caseType = $this->caseType();
		unset($caseType['portalStatusLabels']);
		$plain = $this->controller(caseType: $caseType)->show('zaken', 'zaak', self::CASE_ID);
		$this->assertSame('', $plain->getData()['writableSet']['status']['label']);
	}//end testThePublicStatusLabelIsRenderedUnchanged()

	/**
	 * The citizen sees which fields are editable and which are not, with the
	 * reason, before touching anything.
	 */
	public function testTheCitizenSeesTheWritableSetBeforeTouchingAnything(): void {
		$response = $this->controller()->show('zaken', 'zaak', self::CASE_ID);

		$set = $response->getData()['writableSet'];
		$this->assertSame(['omschrijving', 'toelichting'], $set['writable']);
		$this->assertTrue($set['window']['open']);
		$this->assertSame([], $this->writes);
	}//end testTheCitizenSeesTheWritableSetBeforeTouchingAnything()

	/**
	 * A case whose collection never opted into downloads lists no file at all,
	 * even when the case folder holds one: the folder also holds what staff
	 * added and never released (portaliq#798).
	 */
	public function testTheCaseScreenListsNoFileWhenTheCollectionDidNotOptIn(): void {
		$controller = $this->controller(
			existingFiles: [['id' => 8, 'name' => 'intern-advies.pdf', 'size' => 512]],
			collections: [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak']]
		);

		$response = $controller->show('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['documents']);
	}//end testTheCaseScreenListsNoFileWhenTheCollectionDidNotOptIn()

	/**
	 * Where the case collection opts into downloads, the screen lists only the
	 * files the organisation released, never an internal note beside them.
	 */
	public function testTheCaseScreenListsOnlyReleasedFilesWhenTheCollectionOptsIn(): void {
		$released = ['id' => 7, 'name' => 'besluit.pdf', 'size' => 2048];
		$controller = $this->controller(
			existingFiles: [$released, ['id' => 8, 'name' => 'intern-advies.pdf', 'size' => 512]],
			collections: [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak', 'filesDownload' => true]],
			releasedFiles: [$released]
		);

		$response = $controller->show('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 'released:7', 'title' => 'besluit.pdf', 'kind' => 'document', 'date' => '', 'size' => 2048]], $response->getData()['documents']);
	}//end testTheCaseScreenListsOnlyReleasedFilesWhenTheCollectionOptsIn()

	/**
	 * An opt-in counts only on the case's own register and schema: a
	 * collection of the same app over another schema opens nothing here.
	 */
	public function testAnOptInOnAnotherSchemaOpensNothingOnTheCase(): void {
		$controller = $this->controller(
			existingFiles: [['id' => 8, 'name' => 'intern-advies.pdf', 'size' => 512]],
			collections: [['id' => 'facturen', 'register' => 'zaken', 'schema' => 'factuur', 'filesDownload' => true]]
		);

		$response = $controller->show('zaken', 'zaak', self::CASE_ID);

		$this->assertSame([], $response->getData()['documents']);
	}//end testAnOptInOnAnotherSchemaOpensNothingOnTheCase()

	/**
	 * The case type dossiq would declare.
	 *
	 * @param array<int, string>|null $amendmentStatuses The statuses the amendment window is open in.
	 * @param array<int, string>|null $documentStatuses The statuses documents may be added in.
	 *
	 * @return array<string, mixed>
	 */
	private function caseType(?array $amendmentStatuses = null, ?array $documentStatuses = null, ?array $withdrawal = null): array {
		$caseType = [
			'id' => 'type-1',
			CitizenWritableSetResolver::WRITABLE_PROPERTY => [
				['field' => 'omschrijving', 'audiences' => ['client']],
				['field' => 'toelichting', 'audiences' => ['client']],
			],
			CitizenWritableSetResolver::WINDOW_PROPERTY => [
				'openStatuses' => ($amendmentStatuses ?? ['ontvangen', 'aanvullen']),
				'closedReason' => 'De aanvraag is in behandeling genomen.',
			],
			CitizenWritableSetResolver::DOCUMENTS_PROPERTY => [
				'openStatuses' => ($documentStatuses ?? ['ontvangen', 'aanvullen']),
				'closedReason' => 'De zaak neemt geen stukken meer aan.',
			],
			CitizenWritableSetResolver::STATUS_LABELS_PROPERTY => [
				'ontvangen' => [
					'label' => 'Wij hebben uw aanvraag ontvangen',
					'description' => 'U hoort binnen acht weken van ons.',
				],
			],
		];

		if ($withdrawal !== null) {
			$caseType[CitizenWritableSetResolver::WITHDRAWAL_PROPERTY] = $withdrawal;
		}

		return $caseType;
	}//end caseType()

	/**
	 * The update action dossiq would contribute.
	 *
	 * @return array<string, mixed>
	 */
	private function action(): array {
		return [
			'id' => 'amend-request',
			'type' => 'update',
			'register' => 'zaken',
			'schema' => 'zaak',
			'scopeField' => 'indiener',
			'fields' => ['omschrijving', 'toelichting'],
			'citizenWrite' => [
				'typeField' => 'zaaktype',
				'typeRegister' => 'zaken',
				'typeSchema' => 'zaaktype',
				'statusField' => 'status',
				'recordField' => 'portalWrites',
				'documentsField' => 'portalDocuments',
			],
		];
	}//end action()

	/**
	 * The two cases in the fixture: one the citizen's, one another citizen's.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function cases(): array {
		return [
			self::CASE_ID => [
				'id' => self::CASE_ID,
				'indiener' => 'bsn-hash-1',
				'omschrijving' => 'een dakkapel',
				'toelichting' => 'aan de achterzijde',
				'status' => 'ontvangen',
				'zaaktype' => 'type-1',
				// What the case app keeps for its staff and never declares.
				'assignee' => 'behandelaar-7',
				'qualityScore' => 0.9997,
				'@self' => ['id' => self::CASE_ID, 'owner' => 'admin', 'organisation' => 'org-uuid'],
			],
			self::OTHER_CASE_ID => [
				'id' => self::OTHER_CASE_ID,
				'indiener' => 'bsn-hash-2',
				'omschrijving' => 'een uitbouw',
				'status' => 'ontvangen',
				'zaaktype' => 'type-1',
			],
		];
	}//end cases()

	/**
	 * Build the controller over fakes that behave like the real boundary: the
	 * reader answers only rows whose scope field matches the value it was
	 * given, so "not mine" is decided by the data, not by the test.
	 *
	 * @param array<string, mixed>|null $subject The resolved session, or null.
	 * @param array<string, mixed> $fields The fields the request submits.
	 * @param array<string, mixed>|null $caseType The case type the fake reader answers with.
	 * @param array<string, mixed>|null $action The contributed action.
	 * @param array{name: string, content: string}|null $upload The multipart upload.
	 * @param array<int, array<string, mixed>> $existingFiles Documents already on the case.
	 * @param bool $throttleOpen Whether the throttle lets the write through.
	 * @param array<string, mixed> $params Other request parameters.
	 * @param array<int, array<string, mixed>> $collections The contribution's collections.
	 * @param array<int, array<string, mixed>>|null $releasedFiles What the released
	 *        listing answers; null means the same as $existingFiles.
	 */
	private function controller(
		array|null $subject = self::SUBJECT,
		array $fields = [],
		?array $caseType = null,
		?array $action = null,
		?array $upload = null,
		array $existingFiles = [],
		bool $throttleOpen = true,
		array $params = [],
		array $collections = [],
		?array $releasedFiles = null,
		array $taggedFiles = [],
		array $published = [],
		?MandatedCaseReader $mandatedCases = null,
	): CitizenCaseController {
		$action = ($action ?? $this->action());
		$cases = $this->cases();

		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) use ($fields, $params) {
				if ($key === 'fields') {
					return ($fields === [] ? null : $fields);
				}

				if (array_key_exists($key, $params) === true) {
					return $params[$key];
				}

				return $default;
			}
		);
		$request->method('getUploadedFile')->willReturn($this->uploadedFile(upload: $upload));

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn([
			'contributions' => [['app' => 'dossiq', 'actions' => [$action], 'collections' => $collections]],
		]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $id) use ($cases) {
				$this->readScopes[] = $subjectRef;
				$row = ($cases[$id] ?? null);
				if ($row === null || (string)($row[$scopeField] ?? '') !== $subjectRef) {
					return null;
				}

				return $row;
			}
		);

		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			function (
				string $register,
				string $schema,
				string $scopeField,
				string $subjectRef,
				string $organisation,
				string $id,
				array $data,
			) use ($cases) {
				$this->writes[] = ['id' => $id, 'data' => $data];

				return array_merge(($cases[$id] ?? []), $data);
			}
		);

		$fileReader = $this->createMock(PortalFileReader::class);
		$fileReader->method('listFiles')->willReturn($existingFiles);
		$fileReader->method('listReleasedFiles')->willReturn($releasedFiles ?? $existingFiles);
		$fileReader->method('listTaggedFiles')->willReturnCallback(
			static fn (string $register, string $schema, string $id, string $tag): array => ($tag === 'portal:from-applicant' ? $taggedFiles : [])
		);
		$fileReader->method('streamFile')->willReturnCallback(
			function (string $register, string $schema, string $id, string $fileId): ?StreamResponse {
				$this->streamed[] = [$register, $schema, $id, $fileId];
				return $this->createMock(StreamResponse::class);
			}
		);

		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->method('attachFile')->willReturnCallback(
			function (string $register, string $schema, string $id, string $fileName, string $content = '', array $tags = []) {
				$this->attached[] = $fileName;
				$this->attachedTags[] = $tags;

				return ['id' => 1, 'name' => $fileName, 'size' => 4];
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->events[] = $event;
			}
		);

		$caseTypeReader = $this->createMock(CaseTypeReader::class);
		$caseTypeReader->method('readCaseType')->willReturn($caseType ?? $this->caseType());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		return new CitizenCaseController(
			$request,
			$registry,
			$session,
			$reader,
			$writer,
			$fileReader,
			$fileWriter,
			new CitizenWritableSetResolver($caseTypeReader, $l10n),
			new CitizenWriteRecorder($this->createMock(AuditTrailService::class), $dispatcher),
			$this->throttle(open: $throttleOpen),
			new CitizenDocumentUpload(),
			$this->mandateService(),
			$this->treeResolver(),
			$l10n,
			$this->createMock(LoggerInterface::class),
			$this->documents(fileReader: $fileReader, published: $published),
			$mandatedCases
		);
	}//end controller()

	/**
	 * The REAL documents service over the file reader double, a case app
	 * provider whose `caseDocuments` answers `$published` for this case, and an
	 * audit hook that records each download.
	 *
	 * @param PortalFileReader                 $fileReader The file reader double.
	 * @param array<int, array<string, mixed>> $published  What the case app publishes.
	 *
	 * @return CitizenCaseDocuments
	 */
	private function documents(PortalFileReader $fileReader, array $published): CitizenCaseDocuments {
		$provider = new class ($published) {
			/**
			 * @param array<int, array<string, mixed>> $published The documents.
			 */
			public function __construct(private array $published) {
			}

			/**
			 * The documents on one case.
			 *
			 * @param string $caseId The case.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function caseDocuments(string $caseId): array {
				return ($caseId === 'zaak-1' ? $this->published : []);
			}
		};
		$locator = $this->createMock(PortalProviderLocator::class);
		$locator->method('locate')->willReturn($provider);

		$audit = $this->createMock(PortalAuditHook::class);
		$audit->method('download')->willReturnCallback(
			function (string $subjectRef, string $organisation, string $register, string $schema, string $id): void {
				$this->downloads[] = [$subjectRef, $register, $schema, $id];
			}
		);

		return new CitizenCaseDocuments(
			files: $fileReader,
			published: new PortalCaseDocumentReader(locator: $locator, logger: $this->createMock(LoggerInterface::class)),
			audit: $audit
		);
	}//end documents()

	/**
	 * A real readable upload on disk, so the controller's own multipart read
	 * is exercised rather than stubbed past.
	 *
	 * @param array{name: string, content: string}|null $upload The upload, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function uploadedFile(?array $upload): array {
		if ($upload === null) {
			return [];
		}

		$tmp = (string)tempnam(sys_get_temp_dir(), 'citizen-write');
		file_put_contents($tmp, $upload['content']);

		return [
			'name' => $upload['name'],
			'type' => 'application/pdf',
			'tmp_name' => $tmp,
			'size' => strlen($upload['content']),
			'error' => 0,
		];
	}//end uploadedFile()

	/**
	 * A throttle that is open, or one that has already been exhausted.
	 *
	 * @param bool $open Whether the write may proceed.
	 */
	private function throttle(bool $open): CitizenWriteThrottle {
		$store = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$store, $open) {
				if ($open === false) {
					return 1000;
				}

				return ($store[$key] ?? null);
			}
		);
		$cache->method('set')->willReturnCallback(
			static function (string $key, $value) use (&$store): bool {
				$store[$key] = $value;

				return true;
			}
		);

		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);

		return new CitizenWriteThrottle($factory);
	}//end throttle()

	/**
	 * A mandate service that answers no mandate: these tests are about a
	 * citizen writing on their OWN case, so nothing here acts under one.
	 *
	 * @return \OCA\Portaliq\Service\Identity\PortalMandateService
	 */
	private function mandateService(): \OCA\Portaliq\Service\Identity\PortalMandateService {
		$mandates = $this->getMockBuilder(\OCA\Portaliq\Service\Identity\PortalMandateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['mandatesFor', 'activeMandate'])
			->getMock();
		$mandates->method('mandatesFor')->willReturn([]);
		$mandates->method('activeMandate')->willReturn(null);

		return $mandates;
	}//end mandateService()

	/**
	 * A party tree resolver that is never asked to walk anything here.
	 *
	 * @return \OCA\Portaliq\Service\Identity\PortalPartyTreeResolver
	 */
	private function treeResolver(): \OCA\Portaliq\Service\Identity\PortalPartyTreeResolver {
		$tree = $this->getMockBuilder(\OCA\Portaliq\Service\Identity\PortalPartyTreeResolver::class)
			->disableOriginalConstructor()
			->onlyMethods(['entitiesFor'])
			->getMock();
		$tree->method('entitiesFor')->willReturn(['entities' => [], 'refused' => false, 'bound' => []]);

		return $tree;
	}//end treeResolver()

	/**
	 * withdrawing-your-own-case-from-the-portal: the applicant ends their own
	 * request. The status is the case app's, the reason travels with it, a
	 * second withdrawal is refused, and a refusal writes nothing.
	 *
	 * @spec openspec/changes/withdrawing-your-own-case-from-the-portal/specs/withdrawing-your-own-case/spec.md
	 */
	public function testAWithdrawalLandsOnTheStatusTheCaseTypeDeclares(): void {
		$controller = $this->controller(
			caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()),
			params: ['reason' => 'Ik ben toch niet verhuisd.', 'status' => 'afgehandeld']
		);

		$response = $controller->withdraw('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $this->writes);
		// The body named `afgehandeld`; the case app said `ingetrokken`.
		$this->assertSame('ingetrokken', $this->writes[0]['data']['status']);
		$this->assertSame('Ik ben toch niet verhuisd.', $this->writes[0]['data']['withdrawalReason']);

	}//end testAWithdrawalLandsOnTheStatusTheCaseTypeDeclares()

	public function testAWithdrawalRaisesItsOwnEventOnceWithTheReason(): void {
		$controller = $this->controller(
			caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()),
			params: ['reason' => 'Ik ben toch niet verhuisd.']
		);

		$controller->withdraw('zaken', 'zaak', self::CASE_ID);

		$withdrawals = array_values(array_filter(
			$this->events,
			static fn (Event $event): bool => $event instanceof \OCA\Portaliq\Event\PortalClientWithdrawalEvent
		));

		$this->assertCount(1, $withdrawals);
		$this->assertSame('Ik ben toch niet verhuisd.', $withdrawals[0]->getReason());
		$this->assertSame('ingetrokken', $withdrawals[0]->getStatus());
		$this->assertSame(self::CASE_ID, $withdrawals[0]->getCaseId());
		// It is not a write event: a rule bound to a citizen write must not
		// fire on a withdrawal, and the other way round.
		$this->assertSame([], array_values(array_filter(
			$this->events,
			static fn (Event $event): bool => $event instanceof PortalClientWriteEvent
		)));

	}//end testAWithdrawalRaisesItsOwnEventOnceWithTheReason()

	public function testACaseTypeThatDeclaresNoWithdrawalAcceptsNone(): void {
		$controller = $this->controller(caseType: $this->caseType());

		$response = $controller->withdraw('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->events);

	}//end testACaseTypeThatDeclaresNoWithdrawalAcceptsNone()

	public function testAClosedWindowRefusesAndWritesNothing(): void {
		$controller = $this->controller(
			caseType: $this->caseType(withdrawal: ['openStatuses' => ['concept'], 'closedReason' => 'Uw aanvraag is al beoordeeld.', 'targetStatus' => 'ingetrokken'])
		);

		$response = $controller->withdraw('zaken', 'zaak', self::CASE_ID);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('Uw aanvraag is al beoordeeld.', $response->getData()['message']);
		$this->assertSame([], $this->writes);

	}//end testAClosedWindowRefusesAndWritesNothing()

	public function testSomebodyElsesCaseCannotBeWithdrawn(): void {
		$controller = $this->controller(caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()));

		$response = $controller->withdraw('zaken', 'zaak', self::OTHER_CASE_ID);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->events);

	}//end testSomebodyElsesCaseCannotBeWithdrawn()

	public function testWithoutASessionNoWithdrawalIsAccepted(): void {
		$controller = $this->controller(subject: null, caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()));

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->withdraw('zaken', 'zaak', self::CASE_ID)->getStatus());
		$this->assertSame([], $this->writes);

	}//end testWithoutASessionNoWithdrawalIsAccepted()

	public function testAThrottledIdentityCannotWithdraw(): void {
		$controller = $this->controller(
			caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()),
			throttleOpen: false
		);

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $controller->withdraw('zaken', 'zaak', self::CASE_ID)->getStatus());
		$this->assertSame([], $this->writes);

	}//end testAThrottledIdentityCannotWithdraw()

	public function testTheCaseStaysReadableAndTheWithdrawalIsBesideIt(): void {
		$controller = $this->controller(
			caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()),
			params: ['reason' => 'Niet meer nodig.']
		);

		$data = $controller->withdraw('zaken', 'zaak', self::CASE_ID)->getData();

		// Nothing is deleted: the answers come back with the case, with the
		// withdrawal's own record appended beside them.
		$this->assertSame('ingetrokken', $data['case']['status']);
		$this->assertNotSame('', (string)$data['case']['withdrawnAt']);
		$this->assertNotSame([], (array)$data['case']['portalWrites']);

	}//end testTheCaseStaysReadableAndTheWithdrawalIsBesideIt()

	/**
	 * The withdrawal declaration a case type would carry.
	 *
	 * @return array<string, mixed>
	 */
	private function withdrawalDeclaration(): array {
		return [
			'openStatuses' => ['ontvangen', 'aanvullen'],
			'closedReason' => 'Uw aanvraag is al beoordeeld.',
			'targetStatus' => 'ingetrokken',
			'confirmText' => 'Als u intrekt, stopt de behandeling.',
		];
	}//end withdrawalDeclaration()

	/**
	 * The case app's documents reach the screen without their file
	 * reference; a decision comes first (cases-documents-on-the-case,
	 * REQ-CDC-001, REQ-CDC-003).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-case-app-declares-which-documents-a-resident-may-see-req-cdc-001
	 */
	public function testShowNeverReturnsAFileReference(): void {
		$response = $this->controller(collections: self::WITH_DOCUMENTS, published: self::PUBLISHED)->show('zaken', 'zaak', self::CASE_ID);

		$documents = $response->getData()['documents'];
		$this->assertSame(
			[
				['id' => 'besluit-1', 'title' => 'Besluit op uw aanvraag', 'kind' => 'decision', 'date' => '2026-09-01', 'mimeType' => 'application/pdf', 'size' => 2048],
				['id' => 'brief-1', 'title' => 'Ontvangstbevestiging', 'kind' => 'document', 'date' => '2026-08-01'],
			],
			$documents
		);
		$json = (string)json_encode($response->getData());
		$this->assertStringNotContainsString('doc-obj-', $json);
		$this->assertStringNotContainsString('fileId', $json);
		$this->assertSame('Stukken', $response->getData()['documentsLabel']);
	}//end testShowNeverReturnsAFileReference()

	/**
	 * The resident's own uploads are listed under "Sent by you", and a file in
	 * the case folder without the tag is not (REQ-CDC-004).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
	 */
	public function testShowListsTaggedUploadsOnly(): void {
		$upload = ['id' => 5, 'name' => 'bewijs.pdf', 'size' => 100];
		$response = $this->controller(
			existingFiles: [$upload, ['id' => 8, 'name' => 'intern-advies.pdf', 'size' => 512]],
			collections: self::WITH_DOCUMENTS,
			taggedFiles: [$upload],
			published: self::PUBLISHED
		)->show('zaken', 'zaak', self::CASE_ID);

		$documents = $response->getData()['documents'];
		$this->assertSame(['id' => 'upload:5', 'title' => 'bewijs.pdf', 'kind' => 'yours', 'date' => '', 'size' => 100], end($documents));
		$this->assertStringNotContainsString('intern-advies', (string)json_encode($documents));
	}//end testShowListsTaggedUploadsOnly()

	/**
	 * Without a documents method the resident sees only their own uploads
	 * (REQ-CDC-005 empty state otherwise).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-a-case-with-nothing-published-says-so-req-cdc-005
	 */
	public function testShowWithoutProviderListsOnlyUploads(): void {
		$upload = ['id' => 5, 'name' => 'bewijs.pdf', 'size' => 100];
		$response = $this->controller(
			existingFiles: [$upload, ['id' => 8, 'name' => 'intern-advies.pdf', 'size' => 512]],
			collections: [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak']],
			taggedFiles: [$upload],
			published: self::PUBLISHED
		)->show('zaken', 'zaak', self::CASE_ID);

		$this->assertSame([['id' => 'upload:5', 'title' => 'bewijs.pdf', 'kind' => 'yours', 'date' => '', 'size' => 100]], $response->getData()['documents']);
	}//end testShowWithoutProviderListsOnlyUploads()

	/**
	 * A published document streams from where the app said it lives
	 * (REQ-CDC-002).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	public function testStreamsAPublishedDocument(): void {
		$response = $this->controller(collections: self::WITH_DOCUMENTS, published: self::PUBLISHED)->document('zaken', 'zaak', self::CASE_ID, 'besluit-1');

		$this->assertInstanceOf(StreamResponse::class, $response);
		$this->assertSame([['zaken', 'document', 'doc-obj-2', '72']], $this->streamed);
	}//end testStreamsAPublishedDocument()

	/**
	 * Another resident's case answers 404 and streams nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	public function testForeignCaseIs404(): void {
		$response = $this->controller(collections: self::WITH_DOCUMENTS, published: self::PUBLISHED)->document('zaken', 'zaak', self::OTHER_CASE_ID, 'besluit-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->streamed);
	}//end testForeignCaseIs404()

	/**
	 * An id the app did not publish on this case answers the same 404.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	public function testUnlistedIdIs404(): void {
		$controller = $this->controller(collections: self::WITH_DOCUMENTS, published: self::PUBLISHED);
		foreach (['besluit-9', '72', 'released:72', ''] as $guess) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $controller->document('zaken', 'zaak', self::CASE_ID, $guess)->getStatus(), $guess);
		}

		$this->assertSame([], $this->streamed);
	}//end testUnlistedIdIs404()

	/**
	 * A download is audited with the case it was made from.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-every-listed-document-opens-from-the-case-screen-req-cdc-002
	 */
	public function testDownloadIsAudited(): void {
		$this->controller(collections: self::WITH_DOCUMENTS, published: self::PUBLISHED)->document('zaken', 'zaak', self::CASE_ID, 'besluit-1');

		$this->assertSame([['bsn-hash-1', 'zaken', 'zaak', self::CASE_ID]], $this->downloads);
	}//end testDownloadIsAudited()

	/**
	 * `upload:<fileId>` streams a tagged upload from the case folder, and
	 * nothing for a file in the folder without the tag (REQ-CDC-004).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
	 */
	public function testUntaggedFolderFileIs404(): void {
		$upload = ['id' => 5, 'name' => 'bewijs.pdf', 'size' => 100];
		$controller = $this->controller(
			existingFiles: [$upload, ['id' => 8, 'name' => 'intern-advies.pdf', 'size' => 512]],
			taggedFiles: [$upload]
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->document('zaken', 'zaak', self::CASE_ID, 'upload:8')->getStatus());
		$this->assertSame([], $this->streamed);
		$this->assertInstanceOf(StreamResponse::class, $controller->document('zaken', 'zaak', self::CASE_ID, 'upload:5'));
		$this->assertSame([['zaken', 'zaak', self::CASE_ID, '5']], $this->streamed);
	}//end testUntaggedFolderFileIs404()

	/**
	 * An upload through the portal carries the resident's tag.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
	 */
	public function testAnUploadIsTaggedAsTheResidents(): void {
		$this->controller(upload: ['name' => 'bewijs.pdf', 'content' => 'data'])->addDocument('zaken', 'zaak', self::CASE_ID);

		$this->assertSame([['portal:from-applicant']], $this->attachedTags);
	}//end testAnUploadIsTaggedAsTheResidents()

	/**
	 * The collection the resident reads their cases in, declaring what they
	 * may see of one.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private const DECLARING_FIELDS = [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak', 'fields' => ['title', 'status']]];

	/**
	 * The case screen receives the fields the collection declares and the
	 * ones the screen itself works with, never a field the case app keeps for
	 * its staff (citizen-case-shows-only-its-fields).
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function testTheCaseScreenReceivesOnlyTheDeclaredFields(): void {
		$data = $this->controller(collections: self::DECLARING_FIELDS)->show('zaken', 'zaak', self::CASE_ID)->getData();

		$this->assertSame(['id', '@self', 'omschrijving', 'status', 'toelichting'], $this->sortedKeys($data['case']));
		$this->assertSame(['id' => self::CASE_ID], $data['case']['@self']);
		// The full row still decides the writable set on the server.
		$this->assertSame(['omschrijving', 'toelichting'], $data['writableSet']['writable']);
	}//end testTheCaseScreenReceivesOnlyTheDeclaredFields()

	/**
	 * The case screen reads the closed marker of the case's own collection,
	 * the one "My cases" files it under Closed by: a closed case offers no
	 * change, no document and no withdrawal, and refuses an amendment.
	 *
	 * @spec openspec/changes/citizen-case-ended-shows-only-its-state/specs/citizen-writes-on-their-own-case/spec.md#requirement-a-case-that-has-ended-offers-nothing-and-explains-nothing
	 */
	public function testACaseItsCollectionMarksClosedHasEnded(): void {
		$closing = [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak', 'kind' => 'cases', 'closedField' => 'status']];
		$caseType = $this->caseType(withdrawal: $this->withdrawalDeclaration());

		$data = $this->controller(caseType: $caseType, collections: $closing)->show('zaken', 'zaak', self::CASE_ID)->getData();
		$this->assertTrue($data['writableSet']['ended']);
		$this->assertSame([], $data['writableSet']['writable']);
		$this->assertFalse($data['writableSet']['documents']['open']);
		$this->assertFalse($data['withdrawal']['open']);

		$open = $this->controller(caseType: $caseType)->show('zaken', 'zaak', self::CASE_ID)->getData();
		$this->assertFalse($open['writableSet']['ended']);
		$this->assertTrue($open['withdrawal']['open']);

		$amend = $this->controller(caseType: $caseType, fields: ['omschrijving' => 'een bedrijfspand'], collections: $closing)
			->amend('zaken', 'zaak', self::CASE_ID);
		$this->assertSame(Http::STATUS_CONFLICT, $amend->getStatus());
		$this->assertSame([], $this->writes);
	}//end testACaseItsCollectionMarksClosedHasEnded()

	/**
	 * The withdrawn case that comes back is projected the same way, and still
	 * carries the withdrawal the screen shows.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function testAWithdrawnCaseComesBackWithoutTheStaffFields(): void {
		$data = $this->controller(
			caseType: $this->caseType(withdrawal: $this->withdrawalDeclaration()),
			params: ['reason' => 'Niet meer nodig.'],
			collections: self::DECLARING_FIELDS
		)->withdraw('zaken', 'zaak', self::CASE_ID)->getData();

		$this->assertSame(
			['id', '@self', 'omschrijving', 'status', 'toelichting', 'withdrawalReason', 'withdrawnAt'],
			$this->sortedKeys($data['case'])
		);
		$this->assertSame('ingetrokken', $data['case']['status']);
		// The record of who wrote it stays on the case, not in the browser.
		$this->assertArrayNotHasKey('portalWrites', $data['case']);
		$this->assertArrayHasKey('portalWrites', $this->writes[0]['data']);
	}//end testAWithdrawnCaseComesBackWithoutTheStaffFields()

	/**
	 * An amended case comes back projected too.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function testAnAmendedCaseComesBackWithoutTheStaffFields(): void {
		$data = $this->controller(
			fields: ['omschrijving' => 'een bedrijfspand'],
			collections: self::DECLARING_FIELDS
		)->amend('zaken', 'zaak', self::CASE_ID)->getData();

		$this->assertSame('een bedrijfspand', $data['case']['omschrijving']);
		$this->assertArrayNotHasKey('assignee', $data['case']);
		$this->assertArrayNotHasKey('qualityScore', $data['case']);
		$this->assertArrayNotHasKey('portalWrites', $data['case']);
	}//end testAnAmendedCaseComesBackWithoutTheStaffFields()

	/**
	 * A malformed declaration narrows to the identifiers, not to the screen's
	 * own fields and never to the whole row.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function testAMalformedDeclarationShowsOnlyTheIdentifiers(): void {
		$data = $this->controller(
			collections: [['id' => 'mijn-zaken', 'register' => 'zaken', 'schema' => 'zaak', 'fields' => 'title']]
		)->show('zaken', 'zaak', self::CASE_ID)->getData();

		$this->assertSame(['id', '@self'], $this->sortedKeys($data['case']));
	}//end testAMalformedDeclarationShowsOnlyTheIdentifiers()

	/**
	 * Only a collection on the case's own register and schema counts: fields
	 * declared over another schema do not open or close anything here.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function testFieldsDeclaredOnAnotherSchemaDoNotApply(): void {
		$data = $this->controller(
			collections: [['id' => 'facturen', 'register' => 'zaken', 'schema' => 'factuur', 'fields' => ['bedrag']]]
		)->show('zaken', 'zaak', self::CASE_ID)->getData();

		// No declaration on this schema: the row passes whole, as its list does.
		$this->assertArrayHasKey('assignee', $data['case']);
	}//end testFieldsDeclaredOnAnotherSchemaDoNotApply()

	/**
	 * The keys of a row, `id` and `@self` first, the rest sorted.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<int, string>
	 */
	private function sortedKeys(array $row): array {
		$keys = array_values(array_diff(array_keys($row), ['id', '@self']));
		sort($keys);

		return array_merge(
			array_values(array_intersect(['id', '@self'], array_keys($row))),
			$keys
		);
	}//end sortedKeys()
}//end class
