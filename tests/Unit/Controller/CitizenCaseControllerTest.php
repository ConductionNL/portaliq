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
use OCA\Portaliq\Service\CitizenWriteThrottle;
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
 * @uses   \OCA\Portaliq\Event\PortalClientWriteEvent
 * @uses   \OCA\Portaliq\Service\CitizenWritableSetResolver
 * @uses   \OCA\Portaliq\Service\CitizenWriteRecorder
 * @uses   \OCA\Portaliq\Service\CitizenWriteThrottle
 * @uses   \OCA\Portaliq\Service\PortalSessionService
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
			'contributions' => [['app' => 'dossiq', 'actions' => [$action]]],
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

		$fileWriter = $this->createMock(PortalFileWriter::class);
		$fileWriter->method('attachFile')->willReturnCallback(
			function (string $register, string $schema, string $id, string $fileName) {
				$this->attached[] = $fileName;

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
			$this->createMock(LoggerInterface::class)
		);
	}//end controller()

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
}//end class
