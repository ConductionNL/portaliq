<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\Identity\PortalMandateService;
use OCA\Portaliq\Service\PortalCaseListReader;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;

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
	 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md#requirement-open-and-closed-cases-are-told-apart-by-a-declared-field-req-cmc-002
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
	 * cases-my-cases-page REQ-CMC-001: newest first also when the row carries
	 * no `created` of its own, by the record's own creation date.
	 *
	 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
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
