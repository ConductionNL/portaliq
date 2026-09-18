<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

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
