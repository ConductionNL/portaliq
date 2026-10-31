<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DestinationMapping;
use PHPUnit\Framework\TestCase;

/**
 * submit-creates-the-case-directly REQ-PIFO-004/007: a binding's mapping onto
 * its destination, built against the real dossiq `case` schema (PR #3655).
 *
 * @covers \OCA\Portaliq\Service\Intake\DestinationMapping
 *
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md
 */
class DestinationMappingTest extends TestCase {

	public function testTheBindingsDestinationIsReadAndTheOldPairStillCounts(): void {
		$mapping = new DestinationMapping();

		$this->assertSame(
			['register' => 'dossiq', 'schema' => 'case'],
			$mapping->destinationOf(binding: ['destination' => ['register' => 'dossiq', 'schema' => 'case'], 'caseRegister' => 'x', 'caseSchema' => 'y'])
		);
		$this->assertSame(['register' => 'dossiq', 'schema' => 'case'], $mapping->destinationOf(binding: ['caseRegister' => 'dossiq', 'caseSchema' => 'case']));
		$this->assertNull($mapping->destinationOf(binding: ['deliverTo' => 'wooRequest']));
		$this->assertNull($mapping->destinationOf(binding: ['destination' => ['register' => 'dossiq']]));
	}//end testTheBindingsDestinationIsReadAndTheOldPairStillCounts()

	public function testEveryFieldWritesItsPropertyAndABindingMayRenameOne(): void {
		$built = (new DestinationMapping())->mappingFor(
			binding: ['destination' => ['register' => 'dossiq', 'schema' => 'case', 'fields' => ['onderwerp' => 'title']]],
			fields: [
				['name' => 'onderwerp', 'type' => 'text', 'maxLength' => 200],
				['name' => 'description', 'type' => 'textarea'],
				['name' => 'ignored-without-name-is-skipped', 'type' => 'text', 'property' => 'contestedCase'],
				['type' => 'paragraph'],
			],
			schema: $this->caseSchema()
		);

		$this->assertSame(['onderwerp', 'description', 'ignored-without-name-is-skipped'], array_column($built['fields'], 'field'));
		$this->assertSame(['title', 'description', 'contestedCase'], array_column($built['fields'], 'property'));
		$this->assertSame(200, $built['fields'][0]['maxLength']);
	}//end testEveryFieldWritesItsPropertyAndABindingMayRenameOne()

	public function testTheServerSetsTheTypeTheSubjectAndOnlyTheValuesTheDestinationDeclares(): void {
		$built = (new DestinationMapping())->mappingFor(
			binding: [
				'typeSchema' => 'caseType',
				'typeId' => '0b5c1e9e-4f3a-4b8e-9c7d-2a1f0e6d5c4b',
				'destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject', 'fixed' => ['channel' => 'web']],
			],
			fields: [['name' => 'title', 'type' => 'text']],
			schema: $this->caseSchema(),
			subjectRef: 'sub-123',
			server: ['externalReference' => 'AANVRAAG-ABC12345', 'statements' => [['key' => 'truth']], 'portal' => 'gemeente-x', 'origin' => '']
		);

		$this->assertSame('0b5c1e9e-4f3a-4b8e-9c7d-2a1f0e6d5c4b', $built['fixed']['caseType']);
		$this->assertSame('sub-123', $built['fixed']['portalSubject']);
		$this->assertSame('AANVRAAG-ABC12345', $built['fixed']['externalReference']);
		$this->assertSame('web', $built['fixed']['channel']);
		// The dossiq case declares neither `statements` nor `portal`: they are not written.
		$this->assertArrayNotHasKey('statements', $built['fixed']);
		$this->assertArrayNotHasKey('portal', $built['fixed']);
	}//end testTheServerSetsTheTypeTheSubjectAndOnlyTheValuesTheDestinationDeclares()

	/**
	 * A landing-page form's campaign fields reach only a destination that declares
	 * them, and the fixed values stay the server's (site-organisation-registration-form).
	 *
	 * @return void
	 */
	public function testCampaignFieldsGoOnlyWhereTheDestinationDeclaresThem(): void {
		$binding = ['destination' => ['register' => 'stackiq', 'schema' => 'organization', 'fixed' => ['registrationStatus' => 'pending']]];
		$fields = [['name' => 'name'], ['name' => 'utmFirstTouch'], ['name' => 'referrer'], ['name' => 'nonsense']];

		$organisation = (new DestinationMapping())->mappingFor(binding: $binding, fields: $fields, schema: ['properties' => ['name' => [], 'registrationStatus' => []]]);
		$lead = (new DestinationMapping())->mappingFor(binding: $binding, fields: $fields, schema: ['properties' => ['name' => [], 'referrer' => []]]);

		$this->assertSame(['name', 'nonsense'], array_column($organisation['fields'], 'property'));
		$this->assertSame(['name', 'referrer', 'nonsense'], array_column($lead['fields'], 'property'));
		$this->assertSame('pending', $organisation['fixed']['registrationStatus']);
	}//end testCampaignFieldsGoOnlyWhereTheDestinationDeclaresThem()

	public function testAnAnonymousSubmitStampsNoSubject(): void {
		$built = (new DestinationMapping())->mappingFor(
			binding: ['destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject']],
			fields: [],
			schema: $this->caseSchema()
		);

		$this->assertArrayNotHasKey('portalSubject', $built['fixed']);
	}//end testAnAnonymousSubmitStampsNoSubject()

	public function testAFieldTheClientNamesCannotOverrideTheSubject(): void {
		$built = (new DestinationMapping())->mappingFor(
			binding: ['destination' => ['register' => 'dossiq', 'schema' => 'case', 'subjectField' => 'portalSubject']],
			fields: [['name' => 'portalSubject', 'type' => 'text']],
			schema: $this->caseSchema(),
			subjectRef: 'sub-123'
		);

		// OpenRegister applies `fixed` after the mapped fields, so the server's value wins.
		$this->assertSame('sub-123', $built['fixed']['portalSubject']);
	}//end testAFieldTheClientNamesCannotOverrideTheSubject()

	public function testEachFindingLandsOnTheFieldThatWritesItsProperty(): void {
		$mapping = (new DestinationMapping())->mappingFor(
			binding: ['destination' => ['register' => 'dossiq', 'schema' => 'case', 'fields' => ['onderwerp' => 'title']]],
			fields: [['name' => 'onderwerp', 'type' => 'text']],
			schema: $this->caseSchema()
		);

		$errors = (new DestinationMapping())->fieldErrors(
			findings: [
				['property' => 'title', 'code' => 'type-mismatch', 'message' => 'Too long.'],
				['property' => 'title', 'code' => 'other', 'message' => 'Second message on the same field is dropped.'],
				['property' => 'caseType', 'code' => 'required-unmapped', 'message' => 'No case type.'],
				['field' => 'explicit', 'property' => 'x', 'code' => 'c', 'message' => 'Named field.'],
			],
			mapping: $mapping
		);

		$this->assertSame(['onderwerp' => 'Too long.', DestinationMapping::FORM_LEVEL => 'No case type.', 'explicit' => 'Named field.'], $errors);
	}//end testEachFindingLandsOnTheFieldThatWritesItsProperty()

	/**
	 * The real dossiq `case` schema of PR #3655.
	 *
	 * @return array<string, mixed>
	 */
	private function caseSchema(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../Fixtures/dossiq-case-schema-d179.json'), true)['schema'];
	}//end caseSchema()
}//end class
