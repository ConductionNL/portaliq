<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ContributionController;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\CaseTypeNames;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalActionForwarder;
use OCA\Portaliq\Service\PortalAuditHook;
use OCA\Portaliq\Service\PortalFileReader;
use OCA\Portaliq\Service\PortalFileWriter;
use OCA\Portaliq\Service\PortalInboxReader;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSchemaReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\SubmissionReceiptService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A `cases` block's cards name each case's type, as Mijn zaken does: the
 * collection read of a `kind: cases` collection with a `caseTypeSource`
 * stamps `_caseTypeName` (site-mijn-omgeving-components REQ-SMO-030, wave 4).
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
 */
class ContributionControllerCaseTypeNamesTest extends TestCase {
	/**
	 * The resident.
	 */
	private const SUBJECT = ['subjectRef' => 'bsn:1', 'audience' => 'citizen', 'organisation' => '', 'trust' => 'substantial', 'jti' => 'jti-1'];

	/**
	 * Dossiq's cases collection, with where its case types live.
	 */
	private const COLLECTION = [
		'id' => 'mijnZaken',
		'kind' => 'cases',
		'register' => 'dossiq',
		'schema' => 'case',
		'scopeField' => 'portalSubject',
		'caseTypeField' => 'zaaktype',
		'caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'publicName'],
	];

	/**
	 * Each case of a known type carries its name; an unknown type and a row
	 * without a type carry none, never an id.
	 *
	 * @return void
	 */
	public function testEachCaseOfACasesCollectionCarriesItsTypeName(): void {
		$rows = [
			['id' => 'z1', 'title' => 'Woo bomenkap', 'zaaktype' => 'type-woo'],
			['id' => 'z2', 'title' => 'Woo speeltuin', 'zaaktype' => ['uuid' => 'type-woo']],
			['id' => 'z3', 'title' => 'Onbekend', 'zaaktype' => 'type-gone'],
			['id' => 'z4', 'title' => 'Zonder type'],
		];

		$objects = $this->controller(collection: self::COLLECTION, rows: $rows)->collection('dossiq', 'case')->getData()['objects'];

		$this->assertSame('Woo-verzoek', $objects[0]['_caseTypeName']);
		$this->assertSame('Woo-verzoek', $objects[1]['_caseTypeName']);
		$this->assertArrayNotHasKey('_caseTypeName', $objects[2]);
		$this->assertArrayNotHasKey('_caseTypeName', $objects[3]);
	}//end testEachCaseOfACasesCollectionCarriesItsTypeName()

	/**
	 * Any other kind of collection is left exactly as it was read.
	 *
	 * @return void
	 */
	public function testAnotherKindOfCollectionIsLeftAlone(): void {
		$rows = [['id' => 'z1', 'zaaktype' => 'type-woo']];
		$collection = self::COLLECTION;
		unset($collection['kind']);

		$objects = $this->controller(collection: $collection, rows: $rows)->collection('dossiq', 'case')->getData()['objects'];

		$this->assertSame($rows, $objects);
	}//end testAnotherKindOfCollectionIsLeftAlone()

	/**
	 * A row that is not a record passes through untouched; the rest are named.
	 *
	 * @return void
	 */
	public function testARowThatIsNotARecordPassesThrough(): void {
		$names = new CaseTypeNames(new CaseTypeReader($this->container(), new NullLogger()));

		$this->assertSame(
			['not a row', ['zaaktype' => 'type-woo', '_caseTypeName' => 'Woo-verzoek']],
			$names->stampRows(rows: ['not a row', ['zaaktype' => 'type-woo']], collection: self::COLLECTION)
		);
	}//end testARowThatIsNotARecordPassesThrough()

	/**
	 * The controller under test, reading the given rows.
	 *
	 * @param array<string, mixed>             $collection The collection the resident may read.
	 * @param array<int, array<string, mixed>> $rows       The rows the scoped read answers.
	 *
	 * @return ContributionController
	 */
	private function controller(array $collection, array $rows): ContributionController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([['Authorization', 'Bearer token']]);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $default);

		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'collections' => [$collection]]]]);

		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(self::SUBJECT);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn($rows);

		return new ContributionController(
			request: $request,
			registry: $registry,
			session: $session,
			reader: $reader,
			writer: $this->createMock(PortalObjectWriter::class),
			fileWriter: $this->createMock(PortalFileWriter::class),
			fileReader: $this->createMock(PortalFileReader::class),
			schemaReader: $this->createMock(PortalSchemaReader::class),
			inboxReader: $this->createMock(PortalInboxReader::class),
			auditHook: $this->createMock(PortalAuditHook::class),
			forwarder: $this->createMock(PortalActionForwarder::class),
			auditor: $this->createMock(AuditTrailService::class),
			receiptService: $this->createMock(SubmissionReceiptService::class),
			notificationDispatch: $this->createMock(NotificationDispatchService::class),
			logger: $this->createMock(LoggerInterface::class),
			typeNames: new CaseTypeNames(new CaseTypeReader($this->container(), new NullLogger()))
		);
	}//end controller()

	/**
	 * A container whose object service answers one case type, Woo-verzoek.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$types = new class {
			/**
			 * @param string $register The register.
			 *
			 * @return void
			 */
			public function setRegister(string $register): void {
			}

			/**
			 * @param string $schema The schema.
			 *
			 * @return void
			 */
			public function setSchema(string $schema): void {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac RBAC.
			 * @param bool $_multitenancy Multitenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return [['id' => 'type-woo', 'title' => 'Woo intern', 'publicName' => 'Woo-verzoek']];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($types);

		return $container;

	}//end container()
}//end class
