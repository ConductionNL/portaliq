<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationLookup;
use OCA\Portaliq\Service\Intake\DestinationMapping;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSchemaReader;
use PHPUnit\Framework\TestCase;

/**
 * submit-creates-the-case-directly 4.2 and 2.4: an earlier reference finds
 * the case through `externalReference`; a case number finds it only for the
 * signed-in subject it belongs to, because a case number counts up.
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationLookup
 *
 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.2
 * @uses \OCA\Portaliq\Service\Intake\DestinationMapping
 */
class DestinationLookupTest extends TestCase {

	/**
	 * The case rows the reader holds.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $rows = [
		[
			'identifier' => '2026-0042',
			'externalReference' => 'AANVRAAG-ABC12345',
			'portalSubject' => 'sub-123',
			'receivedAt' => '2026-10-11T09:00:00+00:00',
			'termStartsAt' => '2026-10-13T09:00:00+02:00',
			'deadline' => '2026-11-24',
			'description' => 'Not a confirmation property.',
		],
	];

	public function testAnEarlierReferenceFindsTheCaseForAnyone(): void {
		$found = $this->lookup()->find(portal: 'gemeente-x', reference: 'AANVRAAG-ABC12345');

		$this->assertNotNull($found);
		$this->assertSame('2026-0042', $found['object']['identifier']);
	}//end testAnEarlierReferenceFindsTheCaseForAnyone()

	public function testACaseNumberAloneFindsNothing(): void {
		$this->assertNull($this->lookup()->find(portal: 'gemeente-x', reference: '2026-0042'));
	}//end testACaseNumberAloneFindsNothing()

	public function testACaseNumberFindsTheCaseForItsOwnSubjectOnly(): void {
		$this->assertNotNull($this->lookup()->find(portal: 'gemeente-x', reference: '2026-0042', subjectRef: 'sub-123'));
		$this->assertNull($this->lookup()->find(portal: 'gemeente-x', reference: '2026-0042', subjectRef: 'sub-999'));
	}//end testACaseNumberFindsTheCaseForItsOwnSubjectOnly()

	public function testABindingWithoutASubjectPropertyNeverAnswersACaseNumber(): void {
		$this->assertNull($this->lookup(subjectField: '')->find(portal: 'gemeente-x', reference: '2026-0042', subjectRef: 'sub-123'));
	}//end testABindingWithoutASubjectPropertyNeverAnswersACaseNumber()

	public function testTheStatusNamesTheCaseAndTheConfirmationMoments(): void {
		$lookup = $this->lookup();
		$status = $lookup->statusOf(found: $lookup->find(portal: 'gemeente-x', reference: 'AANVRAAG-ABC12345'), reference: 'AANVRAAG-ABC12345');

		$this->assertSame('registered', $status['state']);
		$this->assertSame('2026-0042', $status['caseId']);
		$this->assertSame('2026-11-24', $status['dueAt']);
		$this->assertSame('2026-10-11T09:00:00+00:00', $status['submittedAt']);
		$this->assertEqualsCanonicalizing(['identifier', 'receivedAt', 'termStartsAt', 'deadline'], array_keys($status['confirmation']));
	}//end testTheStatusNamesTheCaseAndTheConfirmationMoments()

	public function testNothingIsFoundWithoutAPortalOrAReference(): void {
		$this->assertNull($this->lookup()->find(portal: '', reference: 'AANVRAAG-ABC12345'));
		$this->assertNull($this->lookup()->find(portal: 'gemeente-x', reference: '  '));
	}//end testNothingIsFoundWithoutAPortalOrAReference()

	/**
	 * The lookup over one published binding into the real dossiq case schema.
	 *
	 * @param string $subjectField The binding's subject property.
	 *
	 * @return DestinationLookup
	 */
	private function lookup(string $subjectField = 'portalSubject'): DestinationLookup {
		$bindings = $this->createMock(PortalFormBindingResolver::class);
		$bindings->method('publishedBindings')->willReturn([
			['portal' => 'gemeente-x', 'route' => 'bezwaar', 'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => $subjectField]],
			// A second binding into the same destination is read once.
			['portal' => 'gemeente-x', 'route' => 'klacht', 'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => $subjectField]],
			['portal' => 'gemeente-x', 'route' => 'extern'],
		]);

		$schemas = $this->createMock(PortalSchemaReader::class);
		$schemas->expects($this->atMost(1))->method('readSchema')->willReturn(
			json_decode((string)file_get_contents(__DIR__ . '/../../../Fixtures/dossiq-case-schema-d179.json'), true)['schema']
		);

		$rows   = $this->rows;
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturnCallback(
			static function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation = '', int $limit = 200, string $scopeClaim = '', string $contributingApp = '', mixed $via = null, string $audience = '', mixed $fields = null, array $filter = []) use ($rows): array {
				// A reader that filters on the scope field only, the way portal reads scope:
				// the lookup must check the reference itself.
				return array_values(array_filter($rows, static fn (array $row): bool => (string)($row[$scopeField] ?? '') === $subjectRef));
			}
		);

		return new DestinationLookup(bindings: $bindings, reader: $reader, schemas: $schemas, mapping: new DestinationMapping());
	}//end lookup()
}//end class
