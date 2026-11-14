<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\RegisteredDetailsShape;
use PHPUnit\Framework\TestCase;

/**
 * The registered details a person or company sees, read from the BRP and
 * KVK answers: names are joined from their parts when the full one is
 * missing, blanks and non-text parts are left out, and the company takes
 * its main branch's name.
 *
 * @covers \OCA\Portaliq\Service\Identity\RegisteredDetailsShape
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
class RegisteredDetailsShapeTest extends TestCase {

	public function testAPersonsNameIsJoinedFromItsPartsWhenTheFullOneIsMissing(): void {
		$person = (new RegisteredDetailsShape())->person([
			'naam' => ['voornamen' => ' Sanne ', 'voorvoegsel' => 'de', 'geslachtsnaam' => 'Vries'],
			'geboorte' => ['datum' => ['datum' => '1990-05-01', 'langFormaat' => '1 mei 1990']],
			'verblijfplaats' => ['verblijfadres' => [
				'korteStraatnaam' => 'Dorpsstr',
				'huisnummer' => 12,
				'huisletter' => 'a',
				'huisnummertoevoeging' => ' 2 ',
				'postcode' => '1234AB',
				'woonplaats' => 'Tilburg',
			]],
		]);

		$this->assertSame(
			[
				'name' => 'Sanne de Vries',
				'birthDate' => '1990-05-01',
				'address' => ['street' => 'Dorpsstr', 'number' => '12a2', 'postcode' => '1234AB', 'city' => 'Tilburg'],
				'residentsAtAddress' => null,
			],
			$person
		);
	}//end testAPersonsNameIsJoinedFromItsPartsWhenTheFullOneIsMissing()

	public function testTheFullNameAndTheOfficialStreetWin(): void {
		$person = (new RegisteredDetailsShape())->person([
			'naam' => ['volledigeNaam' => ' S. de Vries ', 'voornamen' => 'Sanne'],
			'geboorte' => ['datum' => '1990-01-01'],
			'verblijfplaats' => ['verblijfadres' => ['officieleStraatnaam' => 'Dorpsstraat', 'korteStraatnaam' => 'Dorpsstr']],
		]);

		$this->assertSame('S. de Vries', $person['name']);
		$this->assertSame('1990-01-01', $person['birthDate']);
		$this->assertSame('Dorpsstraat', $person['address']['street']);
	}//end testTheFullNameAndTheOfficialStreetWin()

	public function testAnEmptyAnswerGivesBlankDetailsAndNonTextPartsAreLeftOut(): void {
		$shape = new RegisteredDetailsShape();

		$this->assertSame(
			['name' => '', 'birthDate' => '', 'address' => ['street' => '', 'number' => '', 'postcode' => '', 'city' => ''], 'residentsAtAddress' => null],
			$shape->person([])
		);
		$this->assertSame('Vries', $shape->person(['naam' => ['voornamen' => ['Sanne'], 'voorvoegsel' => null, 'geslachtsnaam' => 'Vries']])['name']);
	}//end testAnEmptyAnswerGivesBlankDetailsAndNonTextPartsAreLeftOut()

	public function testACompanyTakesItsMainBranchsNameAndListsEveryNumberedBranch(): void {
		$company = (new RegisteredDetailsShape())->company(
			rows: [
				['type' => 'rechtspersoon', 'naam' => 'Holding BV', 'rechtsvorm' => 'BV'],
				[
					'type' => 'hoofdvestiging',
					'handelsnaam' => 'Bakkerij Zuid',
					'vestigingsnummer' => '000012345678',
					'adres' => ['binnenlandsAdres' => ['straatnaam' => 'Markt', 'huisnummer' => 1, 'huisletter' => '', 'postcode' => '5038AA', 'plaats' => 'Tilburg']],
				],
				['type' => 'nevenvestiging', 'naam' => 'Bakkerij Noord', 'vestigingsnummer' => '000087654321', 'straatnaam' => 'Kerkstraat', 'huisnummer' => '5', 'plaats' => 'Breda'],
				['type' => 'hoofdvestiging', 'naam' => 'Tweede hoofd', 'vestigingsnummer' => ''],
			],
			kvkNumber: '12345678'
		);

		$this->assertSame(
			[
				'tradeName' => 'Bakkerij Zuid',
				'kvkNumber' => '12345678',
				'legalForm' => 'BV',
				'branches' => [
					['number' => '000012345678', 'name' => 'Bakkerij Zuid', 'address' => 'Markt 1, 5038AA Tilburg', 'main' => true],
					['number' => '000087654321', 'name' => 'Bakkerij Noord', 'address' => 'Kerkstraat 5, Breda', 'main' => false],
				],
			],
			$company
		);
	}//end testACompanyTakesItsMainBranchsNameAndListsEveryNumberedBranch()

	public function testWithoutAMainBranchTheNameFallsBackToTheLegalEntityThenTheFirstRow(): void {
		$shape = new RegisteredDetailsShape();

		$this->assertSame('RP', $shape->company(rows: [['type' => 'nevenvestiging', 'naam' => 'Eerste'], ['type' => 'rechtspersoon', 'naam' => 'RP']], kvkNumber: '1')['tradeName']);

		$firstRow = $shape->company(rows: [['type' => 'x', 'naam' => 'Eerste', 'rechtsvorm' => ''], ['type' => 'y', 'rechtsvorm' => 'VOF']], kvkNumber: '1');
		$this->assertSame('Eerste', $firstRow['tradeName']);
		$this->assertSame('VOF', $firstRow['legalForm']);
		$this->assertSame([], $firstRow['branches']);

		$this->assertSame(['tradeName' => '', 'kvkNumber' => '9', 'legalForm' => '', 'branches' => []], $shape->company(rows: [], kvkNumber: '9'));
	}//end testWithoutAMainBranchTheNameFallsBackToTheLegalEntityThenTheFirstRow()
}//end class
