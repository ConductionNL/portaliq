<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\RegisteredDetailsShape;
use PHPUnit\Framework\TestCase;

/**
 * identity-registered-details REQ-IRD-001 and REQ-IRD-002: the raw BRP and KvK
 * answers are reduced to the small fixed shape the portal shows, and nothing
 * else of the raw object survives.
 *
 * @covers \OCA\Portaliq\Service\Identity\RegisteredDetailsShape
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
class RegisteredDetailsShapeCasesTest extends TestCase {

	private RegisteredDetailsShape $shape;

	protected function setUp(): void {
		$this->shape = new RegisteredDetailsShape();

	}//end setUp()

	public function testThePersonTakesTheFullNameWhenTheRecordGivesOne(): void {
		$person = $this->shape->person([
			'naam' => ['volledigeNaam' => 'Dr. Jan de Vries', 'voornamen' => 'Jan', 'geslachtsnaam' => 'de Vries'],
			'geboorte' => ['datum' => '1980-04-01'],
			'verblijfplaats' => ['verblijfadres' => [
				'officieleStraatnaam' => 'Dorpsstraat',
				'huisnummer' => '4',
				'huisletter' => 'A',
				'huisnummertoevoeging' => 'bis',
				'postcode' => '1234 AB',
				'woonplaats' => 'Utrecht',
			]],
		]);

		$this->assertSame('Dr. Jan de Vries', $person['name']);
		$this->assertSame('1980-04-01', $person['birthDate']);
		$this->assertSame('Dorpsstraat', $person['address']['street']);
		// The house number parts join with no glue.
		$this->assertSame('4Abis', $person['address']['number']);
		$this->assertSame('1234 AB', $person['address']['postcode']);
		$this->assertSame('Utrecht', $person['address']['city']);
		// The resident count is never guessed (design D5).
		$this->assertNull($person['residentsAtAddress']);

	}//end testThePersonTakesTheFullNameWhenTheRecordGivesOne()

	public function testThePersonNameIsAssembledFromThePartsWhenNoFullNameIsGiven(): void {
		$person = $this->shape->person([
			'naam' => ['voornamen' => 'Anna', 'voorvoegsel' => 'van der', 'geslachtsnaam' => 'Berg'],
		]);

		$this->assertSame('Anna van der Berg', $person['name']);

	}//end testThePersonNameIsAssembledFromThePartsWhenNoFullNameIsGiven()

	public function testAssemblingTheNameSkipsANonScalarPart(): void {
		// A malformed BRP answer can hand an array where a name part should be;
		// the join drops it rather than rendering "Array".
		$person = $this->shape->person([
			'naam' => ['voornamen' => ['unexpected'], 'geslachtsnaam' => 'Jansen'],
		]);

		$this->assertSame('Jansen', $person['name']);

	}//end testAssemblingTheNameSkipsANonScalarPart()

	public function testThePersonFallsBackToTheShortStreetNameAndReadsANestedBirthDate(): void {
		$person = $this->shape->person([
			'naam' => ['volledigeNaam' => 'Kees Bakker'],
			// Some BRP paths nest the date one level deeper.
			'geboorte' => ['datum' => ['datum' => '1975-12-31']],
			'verblijfplaats' => ['verblijfadres' => ['korteStraatnaam' => 'Kerkln', 'huisnummer' => '10']],
		]);

		$this->assertSame('1975-12-31', $person['birthDate']);
		$this->assertSame('Kerkln', $person['address']['street']);
		$this->assertSame('10', $person['address']['number']);

	}//end testThePersonFallsBackToTheShortStreetNameAndReadsANestedBirthDate()

	public function testAnEmptyPersonRecordYieldsTheEmptyShapeAndNeverAFatal(): void {
		$person = $this->shape->person([]);

		$this->assertSame('', $person['name']);
		$this->assertSame('', $person['birthDate']);
		$this->assertSame(['street' => '', 'number' => '', 'postcode' => '', 'city' => ''], $person['address']);
		$this->assertNull($person['residentsAtAddress']);

	}//end testAnEmptyPersonRecordYieldsTheEmptyShapeAndNeverAFatal()

	public function testTheCompanyTakesTheMainBranchNameAndMarksItMain(): void {
		$company = $this->shape->company(
			[
				[
					'type' => 'rechtspersoon',
					'naam' => 'Conduction Holding B.V.',
					'rechtsvorm' => 'Besloten Vennootschap',
				],
				[
					'type' => 'hoofdvestiging',
					'naam' => 'Conduction B.V.',
					'vestigingsnummer' => '000012345678',
					'adres' => ['binnenlandsAdres' => [
						'straatnaam' => 'Nieuwe Gracht',
						'huisnummer' => '7',
						'postcode' => '3512 LD',
						'plaats' => 'Utrecht',
					]],
				],
            ],
			'90001234'
		);

		// The main branch's name wins over the legal entity's.
		$this->assertSame('Conduction B.V.', $company['tradeName']);
		$this->assertSame('90001234', $company['kvkNumber']);
		$this->assertSame('Besloten Vennootschap', $company['legalForm']);
		$this->assertCount(1, $company['branches']);
		$branch = $company['branches'][0];
		$this->assertSame('000012345678', $branch['number']);
		$this->assertSame('Conduction B.V.', $branch['name']);
		$this->assertTrue($branch['main']);
		$this->assertSame('Nieuwe Gracht 7, 3512 LD Utrecht', $branch['address']);

	}//end testTheCompanyTakesTheMainBranchNameAndMarksItMain()

	public function testTheCompanyFallsBackToTheLegalEntityNameThenTheFirstRow(): void {
		$legalOnly = $this->shape->company(
			[['type' => 'rechtspersoon', 'naam' => 'Alleen Rechtspersoon B.V.']],
			'11112222'
		);
		$this->assertSame('Alleen Rechtspersoon B.V.', $legalOnly['tradeName']);
		$this->assertSame([], $legalOnly['branches']);

		$neither = $this->shape->company(
			[['type' => 'nevenvestiging', 'naam' => 'Eerste Rij', 'vestigingsnummer' => '42']],
			'33334444'
		);
		// No main branch and no legal entity: the first row's name stands in.
		$this->assertSame('Eerste Rij', $neither['tradeName']);
		$this->assertFalse($neither['branches'][0]['main']);

	}//end testTheCompanyFallsBackToTheLegalEntityNameThenTheFirstRow()

	public function testACompanyRowWithoutABranchNumberIsNotABranch(): void {
		$company = $this->shape->company(
			[
				['type' => 'rechtspersoon', 'naam' => 'Zonder Vestiging B.V.'],
				['type' => 'hoofdvestiging', 'naam' => 'Zonder Vestiging B.V.'],
			],
			'55556666'
		);

		$this->assertSame([], $company['branches']);

	}//end testACompanyRowWithoutABranchNumberIsNotABranch()

	public function testAKvkV1RowCarriesTheAddressFlatOnTheRow(): void {
		$company = $this->shape->company(
			[[
				'type' => 'hoofdvestiging',
				'handelsnaam' => 'Flat Address V1',
				'vestigingsnummer' => '77',
				'straatnaam' => 'Oudegracht',
				'huisnummer' => '1',
				'postcode' => '3511 AA',
				'plaats' => 'Utrecht',
			]],
			'88889999'
		);

		$this->assertSame('Flat Address V1', $company['tradeName']);
		$this->assertSame('Oudegracht 1, 3511 AA Utrecht', $company['branches'][0]['address']);

	}//end testAKvkV1RowCarriesTheAddressFlatOnTheRow()

	public function testAnEmptyCompanyAnswerKeepsTheNumberAndIsOtherwiseEmpty(): void {
		$company = $this->shape->company([], '12345678');

		$this->assertSame('', $company['tradeName']);
		$this->assertSame('12345678', $company['kvkNumber']);
		$this->assertSame('', $company['legalForm']);
		$this->assertSame([], $company['branches']);

	}//end testAnEmptyCompanyAnswerKeepsTheNumberAndIsOtherwiseEmpty()
}//end class
