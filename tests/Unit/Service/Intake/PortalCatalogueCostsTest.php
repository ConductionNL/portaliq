<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Contribution\FormStepsNormaliser;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\Intake\PortalCatalogueReader;
use OCA\Portaliq\Service\Intake\PortalFee;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A catalogue entry carries the fee its binding's case type declares, read on
 * the server, and the conditions its publication lists (REQ-CSC-001).
 *
 * @spec openspec/changes/catalogue-entry-shows-costs-and-conditions/specs/portal-intake-form/spec.md#requirement-a-catalogue-entry-shows-what-it-costs-and-what-it-asks-of-you-req-csc-001
 */
class PortalCatalogueCostsTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * The case types the double answers, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $caseTypes = [];

	protected function setUp(): void {
		parent::setUp();
		$this->rows      = [];
		$this->caseTypes = [];

	}//end setUp()

	/**
	 * A reader over the seeded rows, with the binding resolver and a fee reader
	 * whose case types come from $this->caseTypes.
	 *
	 * @return PortalCatalogueReader
	 */
	private function catalogue(): PortalCatalogueReader {
		$store     = $this->fakeReader();
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('readCaseType')->willReturnCallback(
			function (string $register, string $schema, string $id): ?array {
				if ($id === 'broken') {
					throw new RuntimeException('case type store down');
				}

				return ($this->caseTypes[$id] ?? null);
			}
		);

		return new PortalCatalogueReader($store, new PortalFormBindingResolver($store, new FormStepsNormaliser()), null, new PortalFee($caseTypes));

	}//end catalogue()

	/**
	 * Seed a published entry and the binding for its route.
	 *
	 * @param string               $route  The route.
	 * @param string               $typeId The case type the binding names.
	 * @param array<string, mixed> $extra  More publication fields.
	 *
	 * @return void
	 */
	private function seedEntryWithBinding(string $route, string $typeId, array $extra = []): void {
		$this->seedRow('publication', ['portal' => 'gemeente-x', 'topic' => 'Wonen', 'title' => 'Parkeervergunning bewoners', 'route' => $route, 'status' => 'published'] + $extra);
		$this->seedRow('portalFormBinding', ['portal' => 'gemeente-x', 'route' => $route, 'status' => 'published', 'typeRegister' => 'zaken', 'typeSchema' => 'zaaktype', 'typeId' => $typeId]);

	}//end seedEntryWithBinding()

	/**
	 * The one entry of the catalogue.
	 *
	 * @return array<string, mixed>
	 */
	private function onlyEntry(): array {
		$topics = $this->catalogue()->topicsFor(portal: 'gemeente-x');
		$this->assertCount(1, $topics);
		$this->assertCount(1, $topics[0]['entries']);

		return $topics[0]['entries'][0];

	}//end onlyEntry()

	public function testAnEntryCarriesTheFeeItsCaseTypeDeclares(): void {
		$this->caseTypes['parkeren'] = ['portalFee' => ['amount' => '38.5', 'currency' => 'eur', 'description' => 'Leges', 'payAction' => 'pay-leges']];
		$this->seedEntryWithBinding('aanvragen/parkeren', 'parkeren');

		$entry = $this->onlyEntry();

		$this->assertSame(['amount' => '38.50', 'currency' => 'EUR', 'description' => 'Leges'], $entry['fee']);
		$this->assertSame('aanvragen/parkeren', $entry['route']);
		$this->assertArrayNotHasKey('conditions', $entry);

	}//end testAnEntryCarriesTheFeeItsCaseTypeDeclares()

	public function testAnEntryWhoseCaseTypeDeclaresNoFeeHasNone(): void {
		$this->caseTypes['verhuizen'] = ['title' => 'Verhuizing'];
		$this->seedEntryWithBinding('aanvragen/verhuizen', 'verhuizen');

		$this->assertNull($this->onlyEntry()['fee']);

	}//end testAnEntryWhoseCaseTypeDeclaresNoFeeHasNone()

	public function testAMalformedFeeIsNoFee(): void {
		$this->caseTypes['kap'] = ['portalFee' => ['amount' => 'gratis', 'currency' => 'EUR', 'payAction' => 'pay']];
		$this->seedEntryWithBinding('aanvragen/kap', 'kap');

		$this->assertNull($this->onlyEntry()['fee']);

	}//end testAMalformedFeeIsNoFee()

	public function testAnEntryWithoutABindingOrAReadableCaseTypeHasNoFee(): void {
		$this->seedRow('publication', ['portal' => 'gemeente-x', 'topic' => 'Wonen', 'title' => 'Zonder binding', 'route' => 'aanvragen/los', 'status' => 'published']);
		$this->assertNull($this->onlyEntry()['fee']);

		$this->rows = [];
		$this->seedEntryWithBinding('aanvragen/stuk', 'broken');
		$this->assertNull($this->onlyEntry()['fee']);

	}//end testAnEntryWithoutABindingOrAReadableCaseTypeHasNoFee()

	public function testConditionsAreListedTrimmed(): void {
		$this->seedEntryWithBinding('aanvragen/parkeren', 'parkeren', ['conditions' => [' U woont in de gemeente ', 'U heeft een auto op uw naam']]);

		$this->assertSame(['U woont in de gemeente', 'U heeft een auto op uw naam'], $this->onlyEntry()['conditions']);

	}//end testConditionsAreListedTrimmed()

	/**
	 * Conditions of any other shape are ignored as a whole.
	 *
	 * @return void
	 */
	public function testMalformedConditionsAreIgnored(): void {
		$shapes = [
			'one string' => 'U woont in de gemeente',
			'an empty line' => ['U woont in de gemeente', '  '],
			'a non-string line' => ['U woont in de gemeente', 3],
			'a map' => ['eerste' => 'U woont in de gemeente'],
			'an empty list' => [],
		];
		foreach ($shapes as $shape => $conditions) {
			$this->rows = [];
			$this->seedEntryWithBinding('aanvragen/parkeren', 'parkeren', ['conditions' => $conditions]);

			$this->assertArrayNotHasKey('conditions', $this->onlyEntry(), $shape);
		}

	}//end testMalformedConditionsAreIgnored()

	public function testWithoutAFeeReaderNoEntryCarriesAFee(): void {
		$this->caseTypes['parkeren'] = ['portalFee' => ['amount' => '38.50', 'currency' => 'EUR', 'payAction' => 'pay']];
		$this->seedEntryWithBinding('aanvragen/parkeren', 'parkeren');
		$store = $this->fakeReader();

		$topics = (new PortalCatalogueReader($store, new PortalFormBindingResolver($store, new FormStepsNormaliser())))->topicsFor(portal: 'gemeente-x');

		$this->assertNull($topics[0]['entries'][0]['fee']);

	}//end testWithoutAFeeReaderNoEntryCarriesAFee()
}//end class
