<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\CaseTypeNames;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\PortalCaseListReader;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * portal-identity-space REQ-PIS-004: "Mijn zaken" is every `kind: cases`
 * collection the subject's contributions declare, each read through its own
 * scoping, so a case a case app attached to the account by claim before the
 * first login is on the list at that first login.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalCaseListReaderTest extends TestCase {

	public function testACaseAttachedByClaimIsListed(): void {
		$reader = $this->readerReturning([['reference' => 'ZAAK-1', 'created' => '2026-09-10T10:00:00+00:00']]);
		$cases = new PortalCaseListReader($reader);

		$rows = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection()));

		$this->assertCount(1, $rows);
		$this->assertSame('ZAAK-1', $rows[0]['reference']);
		$this->assertSame('dossiq', $rows[0]['_source']['appId']);

	}//end testACaseAttachedByClaimIsListed()

	/**
	 * signin-eherkenning-branch REQ-SEB-002: "My cases" for a session
	 * restricted to a branch lists that branch's cases only, and nothing of a
	 * case collection that declares no branch field.
	 */
	public function testARestrictedSessionListsOnlyItsBranchsCases(): void {
		$reader = $this->readerReturning([
			['reference' => 'ZAAK-1', 'vestiging' => '000012345678'],
			['reference' => 'ZAAK-2', 'vestiging' => '000087654321'],
		]);
		$cases = new PortalCaseListReader($reader);
		$subject = $this->subject() + ['branch' => '000012345678', 'branchRestricted' => true];

		$own = $cases->listCases(subject: $subject, aggregate: $this->aggregate(collection: $this->casesCollection() + ['branchField' => 'vestiging']));
		$this->assertSame(['ZAAK-1'], array_column($own, 'reference'));

		$none = $cases->listCases(subject: $subject, aggregate: $this->aggregate(collection: $this->casesCollection()));
		$this->assertSame([], $none);

		$all = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection() + ['branchField' => 'vestiging']));
		$this->assertCount(2, $all, 'a session without a branch lists every case');

	}//end testARestrictedSessionListsOnlyItsBranchsCases()

	/**
	 * operate-show-per-case-type REQ-OSC-002: a case of a type the portal
	 * hides leaves "My cases"; the other types stay, and so does a case whose
	 * type is held as a reference object.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function testHiddenCaseTypeIsNotListed(): void {
		$reader = $this->readerReturning([
			['reference' => 'VERGUNNING-1', 'caseType' => 'omgevingsvergunning'],
			['reference' => 'HANDHAVING-1', 'caseType' => 'handhaving'],
			['reference' => 'HANDHAVING-2', 'caseType' => ['id' => 'handhaving']],
		]);
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$own = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->mandatedCollection()), hiddenCaseTypes: ['handhaving']);
		$this->assertSame(['VERGUNNING-1'], array_column($own, 'reference'));

		$mandated = $cases->listMandatedCases(
			subject: $this->subject(),
			aggregate: $this->aggregate(collection: $this->mandatedCollection()),
			mandates: [$this->mandate()],
			hiddenCaseTypes: ['handhaving']
		);
		$this->assertSame(['VERGUNNING-1'], array_column($mandated, 'reference'));
	}//end testHiddenCaseTypeIsNotListed()

	/**
	 * A portal that hides nothing lists both cases, whatever another portal
	 * of the same organisation hides.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-hidden-case-type-does-not-reach-residents-req-osc-002
	 */
	public function testOtherPortalUnaffected(): void {
		$reader = $this->readerReturning([
			['reference' => 'VERGUNNING-1', 'caseType' => 'omgevingsvergunning'],
			['reference' => 'HANDHAVING-1', 'caseType' => 'handhaving'],
		]);
		$cases = new PortalCaseListReader($reader);

		$rows = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection()), hiddenCaseTypes: []);

		$this->assertSame(['VERGUNNING-1', 'HANDHAVING-1'], array_column($rows, 'reference'));
	}//end testOtherPortalUnaffected()

	public function testTheClaimScopingIsPassedToTheReader(): void {
		$seen = [];
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use (&$seen): array {
				$seen = ['scopeClaim' => $scopeClaim, 'contributingApp' => $contributingApp, 'subjectRef' => $subjectRef];
				return [];
			}
		);

		(new PortalCaseListReader($reader))->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection()));

		$this->assertSame('linkedRequesterId', $seen['scopeClaim']);
		$this->assertSame('dossiq', $seen['contributingApp']);
		$this->assertSame('subject-1', $seen['subjectRef']);

	}//end testTheClaimScopingIsPassedToTheReader()

	public function testACollectionOfAnotherKindIsNotRead(): void {
		$reader = $this->readerReturning([['reference' => 'MSG-1']]);
		$collection = $this->casesCollection();
		$collection['kind'] = 'inbox';

		$rows = (new PortalCaseListReader($reader))->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));

		$this->assertSame([], $rows);

	}//end testACollectionOfAnotherKindIsNotRead()

	public function testACollectionWithNothingToScopeItByIsSkippedRatherThanReadWhole(): void {
		$reader = $this->readerReturning([['reference' => 'EVERY-CASE-EVER']]);
		$collection = $this->casesCollection();
		$collection['scopeField'] = '';
		$collection['scopeClaim'] = '';

		$rows = (new PortalCaseListReader($reader))->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));

		$this->assertSame([], $rows);

	}//end testACollectionWithNothingToScopeItByIsSkippedRatherThanReadWhole()

	public function testACollectionAboveTheSubjectsTrustIsSkipped(): void {
		$reader = $this->readerReturning([['reference' => 'ZAAK-1']]);
		$collection = $this->casesCollection();
		$collection['minTrust'] = 'high';
		$subject = $this->subject();
		$subject['trust'] = 'low';

		$rows = (new PortalCaseListReader($reader))->listCases(subject: $subject, aggregate: $this->aggregate(collection: $collection));

		$this->assertSame([], $rows);

	}//end testACollectionAboveTheSubjectsTrustIsSkipped()

	public function testTheNewestCaseComesFirst(): void {
		$reader = $this->readerReturning([
			['reference' => 'OLD', 'created' => '2026-01-01T00:00:00+00:00'],
			['reference' => 'NEW', 'created' => '2026-09-01T00:00:00+00:00'],
		]);

		$rows = (new PortalCaseListReader($reader))->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection()));

		$this->assertSame(['NEW', 'OLD'], array_column($rows, 'reference'));

	}//end testTheNewestCaseComesFirst()

	/**
	 * cases-my-cases-page REQ-CMC-002: a row is closed when the collection's
	 * declared closed field holds a value; a collection that declares none
	 * marks every row open. The mandated rows carry the same marker.
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-open-and-closed-cases-are-told-apart-by-a-declared-field-req-cmc-002
	 */
	public function testEachRowSaysWhetherItIsClosedByTheDeclaredField(): void {
		$reader = $this->readerReturning([
			['reference' => 'OPEN-1', 'caseType' => 'vergunning', 'endDate' => null],
			['reference' => 'OPEN-2', 'caseType' => 'vergunning', 'endDate' => ''],
			['reference' => 'CLOSED-1', 'caseType' => 'vergunning', 'endDate' => '2026-09-01'],
		]);
		$collection = $this->mandatedCollection();
		$collection['closedField'] = 'endDate';
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$own = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));
		$this->assertSame(
			['OPEN-1' => false, 'OPEN-2' => false, 'CLOSED-1' => true],
			array_column($own, '_closed', 'reference')
		);

		$mandated = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection), mandates: [$this->mandate()]);
		$this->assertSame(
			['OPEN-1' => false, 'OPEN-2' => false, 'CLOSED-1' => true],
			array_column($mandated, '_closed', 'reference')
		);

		unset($collection['closedField']);
		$silent = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));
		$this->assertSame([false, false, false], array_column($silent, '_closed'));

	}//end testEachRowSaysWhetherItIsClosedByTheDeclaredField()

	/**
	 * A case whose status is a reference reads by the words the collection
	 * names for it, on the own list and the mandated one. The raw status stays
	 * on the row, and a row without words carries no label at all.
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/portal-my-cases/spec.md
	 */
	public function testEachRowCarriesTheStatusWordsTheCollectionNames(): void {
		$reader = $this->readerReturning([
			['reference' => 'WORDS', 'caseType' => 'vergunning', 'status' => 'uuid-b001', 'statusPublicLabel' => 'Ontvangen'],
			['reference' => 'BLANK', 'caseType' => 'vergunning', 'status' => 'uuid-b002', 'statusPublicLabel' => ' '],
			['reference' => 'NONE', 'caseType' => 'vergunning', 'status' => 'uuid-b003', 'statusPublicLabel' => null],
		]);
		$collection = $this->mandatedCollection();
		$collection['statusLabelField'] = 'statusPublicLabel';
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$own = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));
		$this->assertSame(['WORDS' => 'Ontvangen'], array_column($own, '_statusLabel', 'reference'));
		$this->assertSame(['WORDS' => 'uuid-b001', 'BLANK' => 'uuid-b002', 'NONE' => 'uuid-b003'], array_column($own, 'status', 'reference'));

		$mandated = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection), mandates: [$this->mandate()]);
		$this->assertSame(['WORDS' => 'Ontvangen'], array_column($mandated, '_statusLabel', 'reference'));

		unset($collection['statusLabelField']);
		$silent = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));
		$this->assertSame([], array_column($silent, '_statusLabel'));

	}//end testEachRowCarriesTheStatusWordsTheCollectionNames()

	/**
	 * cases-my-cases-page REQ-CMC-001: newest first also when the row carries
	 * no `created` of its own, by the record's own creation date.
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
	 */
	public function testTheNewestCaseComesFirstByTheRecordDate(): void {
		$reader = $this->readerReturning([
			['reference' => 'OLD', '@self' => ['created' => '2026-01-01T00:00:00+00:00']],
			['reference' => 'NEW', '@self' => ['created' => '2026-09-01T00:00:00+00:00']],
		]);

		$rows = (new PortalCaseListReader($reader))->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection()));

		$this->assertSame(['NEW', 'OLD'], array_column($rows, 'reference'));

	}//end testTheNewestCaseComesFirstByTheRecordDate()

	public function testWithNoMandateNoOrganisationCaseIsRead(): void {
		$reader = $this->readerReturning([['reference' => 'COLLEGA-1']]);
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$rows = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->mandatedCollection()), mandates: []);

		$this->assertSame([], $rows);

	}//end testWithNoMandateNoOrganisationCaseIsRead()

	public function testACollectionDeclaringNoPartyFieldIsNeverReadByAMandate(): void {
		$reader = $this->readerReturning([['reference' => 'EVERY-CASE-EVER']]);
		$collection = $this->mandatedCollection();
		unset($collection['mandateField']);
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$rows = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection), mandates: [$this->mandate()]);

		$this->assertSame([], $rows);

	}//end testACollectionDeclaringNoPartyFieldIsNeverReadByAMandate()

	public function testTheMandatedCaseNamesTheMandateThatGrantsIt(): void {
		$reader = $this->readerReturning([['reference' => 'COLLEGA-1', 'caseType' => 'vergunning']]);
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$rows = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->mandatedCollection()), mandates: [$this->mandate()]);

		$this->assertCount(1, $rows);
		$this->assertSame('Voorbeeld B.V.', $rows[0]['_mandate']['label']);

	}//end testTheMandatedCaseNamesTheMandateThatGrantsIt()

	public function testAMandateNarrowerThanTheOrganisationListsOnlyItsOwnTypes(): void {
		$reader = $this->readerReturning([
			['reference' => 'VERGUNNING-1', 'caseType' => 'vergunning'],
			['reference' => 'MELDING-1', 'caseType' => 'melding'],
		]);
		$cases = new PortalCaseListReader($reader, $this->mandateService());

		$rows = $cases->listMandatedCases(
			subject: $this->subject(),
			aggregate: $this->aggregate(collection: $this->mandatedCollection()),
			mandates: [$this->mandate(caseTypes: ['vergunning'])]
		);

		$this->assertSame(['VERGUNNING-1'], array_column($rows, 'reference'));

	}//end testAMandateNarrowerThanTheOrganisationListsOnlyItsOwnTypes()

	public function testTheMandateReadsByThePartyFieldNotBySubject(): void {
		$seen = [];
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use (&$seen): array {
				$seen = ['scopeField' => $scopeField, 'subjectRef' => $subjectRef];
				return [];
			}
		);

		(new PortalCaseListReader($reader, $this->mandateService()))->listMandatedCases(
			subject: $this->subject(),
			aggregate: $this->aggregate(collection: $this->mandatedCollection()),
			mandates: [$this->mandate()]
		);

		$this->assertSame('requesterOrganisation', $seen['scopeField']);
		$this->assertSame('kvk-12345678', $seen['subjectRef']);

	}//end testTheMandateReadsByThePartyFieldNotBySubject()

	public function testACaseTypeThatDeclaresNothingIsNotReachableThroughAParent(): void {
		$reader = $this->readerReturning([['reference' => 'SUB-1', 'caseType' => 'vergunning']]);
		$cases = new PortalCaseListReader($reader, $this->mandateService());
		$mandate = $this->mandate();
		$mandate['_entities'] = ['kvk-12345678', 'kvk-subsidiary'];

		$rows = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->mandatedCollection()), mandates: [$mandate]);

		// Only the entity the mandate NAMES answers; the subsidiary's rows are
		// excluded because the collection declares no parent-reachable type.
		foreach ($rows as $row) {
			$this->assertSame('kvk-12345678', $row['_entity']);
		}

	}//end testACaseTypeThatDeclaresNothingIsNotReachableThroughAParent()

	public function testADeclaredTypeIsReachableThroughAParentAndNamesItsEntity(): void {
		$reader = $this->readerReturning([['reference' => 'SUB-1', 'caseType' => 'vergunning']]);
		$collection = $this->mandatedCollection();
		$collection['parentReachableTypes'] = ['vergunning'];
		$cases = new PortalCaseListReader($reader, $this->mandateService());
		$mandate = $this->mandate();
		$mandate['_entities'] = ['kvk-12345678', 'kvk-subsidiary'];

		$rows = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection), mandates: [$mandate]);

		$entities = array_column($rows, '_entity');
		$this->assertContains('kvk-subsidiary', $entities);
		$this->assertSame('Voorbeeld B.V.', $rows[0]['_mandate']['label']);

	}//end testADeclaredTypeIsReachableThroughAParentAndNamesItsEntity()

	public function testAnUndeclaredTypeStaysWithItsOwnEntityEvenWhenOthersAreDeclared(): void {
		$reader = $this->readerReturning([['reference' => 'SUB-M', 'caseType' => 'melding']]);
		$collection = $this->mandatedCollection();
		$collection['parentReachableTypes'] = ['vergunning'];
		$cases = new PortalCaseListReader($reader, $this->mandateService());
		$mandate = $this->mandate();
		$mandate['_entities'] = ['kvk-12345678', 'kvk-subsidiary'];

		$rows = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection), mandates: [$mandate]);

		$this->assertNotContains('kvk-subsidiary', array_column($rows, '_entity'));

	}//end testAnUndeclaredTypeStaysWithItsOwnEntityEvenWhenOthersAreDeclared()

	/**
	 * A real mandate service over a reader that is never used: only `describe`
	 * and `covers` are called on this path, and both are pure.
	 *
	 * @return PortalMandateService
	 */
	/**
	 * site-mijn-omgeving-components REQ-SMO-030: each own and mandated row of
	 * a collection with a case type source names its type, read once per
	 * list, the source's label field first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
	 */
	public function testEachRowCarriesItsCaseTypeName(): void {
		$reader = $this->readerReturning([
			['reference' => 'WOO-1', 'caseType' => 'type-woo'],
			['reference' => 'WOO-2', 'caseType' => ['id' => 'type-woo']],
			['reference' => 'VERG-1', 'caseType' => 'type-verg'],
		]);
		$objects = $this->caseTypeObjects([
			['id' => 'type-woo', 'title' => 'Woo verzoek (intern)', 'publicName' => 'Woo-verzoek'],
			['@self' => ['id' => 'type-verg'], 'name' => 'Omgevingsvergunning'],
		]);
		$cases = new PortalCaseListReader($reader, $this->mandateService(), typeNames: new CaseTypeNames(new CaseTypeReader($this->container($objects), new NullLogger())));
		$collection = $this->mandatedCollection() + ['caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'publicName']];

		$own = $cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection));
		$this->assertSame(
			['WOO-1' => 'Woo-verzoek', 'WOO-2' => 'Woo-verzoek', 'VERG-1' => 'Omgevingsvergunning'],
			array_column($own, '_caseTypeName', 'reference')
		);

		$mandated = $cases->listMandatedCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $collection), mandates: [$this->mandate()]);
		$this->assertSame('Woo-verzoek', $mandated[0]['_caseTypeName']);
		$this->assertSame(1, $objects->reads, 'one read of the source for the whole request');
	}//end testEachRowCarriesItsCaseTypeName()

	/**
	 * A type the source does not know, a row without a type and a collection
	 * without a source all leave the row without a name, never with an id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-my-cases/spec.md#requirement-my-cases-must-name-each-cases-type-req-smo-030
	 */
	public function testAnUnknownTypeLeavesNoName(): void {
		$reader = $this->readerReturning([
			['reference' => 'X-1', 'caseType' => 'type-gone'],
			['reference' => 'X-2'],
		]);
		$objects = $this->caseTypeObjects([['id' => 'type-woo', 'title' => 'Woo-verzoek'], ['id' => 'type-blank', 'title' => '  ']]);
		$names = new CaseTypeNames(new CaseTypeReader($this->container($objects), new NullLogger()));
		$cases = new PortalCaseListReader($reader, typeNames: $names);

		$withSource = $this->casesCollection() + ['caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType']];
		foreach ($cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $withSource)) as $row) {
			$this->assertArrayNotHasKey('_caseTypeName', $row);
		}

		$objects->reads = 0;
		foreach ($cases->listCases(subject: $this->subject(), aggregate: $this->aggregate(collection: $this->casesCollection())) as $row) {
			$this->assertArrayNotHasKey('_caseTypeName', $row);
		}

		$this->assertSame(0, $objects->reads, 'no source, no read');
		$this->assertArrayNotHasKey('_caseTypeName', $names->stamp(row: [], collection: $withSource, typeId: 'type-blank'));
	}//end testAnUnknownTypeLeavesNoName()

	/**
	 * A stand-in for OpenRegister's ObjectService answering case types.
	 *
	 * @param array<int, array<string, mixed>> $types The case types.
	 *
	 * @return object
	 */
	private function caseTypeObjects(array $types): object {
		return new class($types) {
			/**
			 * How many lists were read.
			 *
			 * @var int
			 */
			public int $reads = 0;

			/**
			 * Constructor.
			 *
			 * @param array<int, array<string, mixed>> $types The case types.
			 */
			public function __construct(private array $types) {
			}

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
				$this->reads++;
				return $this->types;
			}
		};
	}//end caseTypeObjects()

	/**
	 * A container that hands out the object service stand-in.
	 *
	 * @param object $objects The stand-in.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $objects): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		return $container;
	}//end container()

	private function mandateService(): PortalMandateService {
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturn([]);

		return new PortalMandateService($reader);
	}//end mandateService()

	/**
	 * A mandate for the company.
	 *
	 * @param array<int, string> $caseTypes The types it covers, or none.
	 *
	 * @return array<string, mixed>
	 */
	private function mandate(array $caseTypes = []): array {
		return [
			'uuid' => 'mandate-1',
			'subjectRef' => 'subject-1',
			'organisation' => 'gemeente-x',
			'onBehalfOf' => 'kvk-12345678',
			'label' => 'Voorbeeld B.V.',
			'caseTypes' => $caseTypes,
			'status' => 'active',
		];
	}//end mandate()

	/**
	 * A case collection that also declares the party field a mandate reads by.
	 *
	 * @return array<string, mixed>
	 */
	private function mandatedCollection(): array {
		$collection = $this->casesCollection();
		$collection['mandateField'] = 'requesterOrganisation';

		return $collection;
	}//end mandatedCollection()

	/**
	 * A reader double answering the same rows for any collection.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows to answer.
	 *
	 * @return PortalObjectReader
	 */
	private function readerReturning(array $rows): PortalObjectReader {
		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();
		$reader->method('readCollection')->willReturn($rows);

		return $reader;
	}//end readerReturning()

	/**
	 * The resolved subject.
	 *
	 * @return array<string, mixed>
	 */
	private function subject(): array {
		return ['subjectRef' => 'subject-1', 'organisation' => 'gemeente-x', 'audience' => 'client', 'trust' => 'substantial'];
	}//end subject()

	/**
	 * One contribution carrying one collection.
	 *
	 * @param array<string, mixed> $collection The collection to carry.
	 *
	 * @return array<string, mixed>
	 */
	private function aggregate(array $collection): array {
		return ['contributions' => [['app' => 'dossiq', 'label' => 'Zaken', 'collections' => [$collection]]]];
	}//end aggregate()

	/**
	 * A case collection scoped by the claim a case app wrote.
	 *
	 * @return array<string, mixed>
	 */
	private function casesCollection(): array {
		return [
			'id' => 'cases',
			'kind' => 'cases',
			'register' => 'dossiq',
			'schema' => 'zaak',
			'scopeField' => 'requester',
			'scopeClaim' => 'linkedRequesterId',
		];
	}//end casesCollection()

}//end class
