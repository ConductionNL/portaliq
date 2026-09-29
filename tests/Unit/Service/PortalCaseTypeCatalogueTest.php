<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeReader;
use OCA\Portaliq\Service\CaseTypeVisibility;
use OCA\Portaliq\Service\Intake\PortalFormBindingResolver;
use OCA\Portaliq\Service\PortalCaseTypeCatalogue;
use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * operate-show-per-case-type REQ-OSC-001 and REQ-OSC-003: the "Case types"
 * page lists what the portal can name (its published forms, a case app's
 * declared case type source, and whatever it already hides), and saving
 * writes only the portal's list.
 *
 * @spec openspec/changes/operate-show-per-case-type/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
class PortalCaseTypeCatalogueTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];
	}//end setUp()

	public function testHiddenTypeStaysListed(): void {
		$this->seedPortal(hidden: [['register' => 'oud', 'schema' => 'zaaktype', 'typeId' => 'afgeschaft', 'label' => 'Afgeschaft']]);
		$this->seedRow('portalFormBinding', [
			'portal' => 'mijn-alkmaar',
			'route' => 'aanvragen/vergunning',
			'status' => 'published',
			'typeRegister' => 'dossiq',
			'typeSchema' => 'caseType',
			'typeId' => 'omgevingsvergunning',
		]);

		$listed = $this->catalogue()->listFor(portal: $this->portal());

		$byId = array_column($listed, null, 'typeId');
		$this->assertSame(['omgevingsvergunning', 'handhaving', 'afgeschaft'], array_keys($byId));
		$this->assertSame('Omgevingsvergunning', $byId['omgevingsvergunning']['label']);
		$this->assertTrue($byId['omgevingsvergunning']['shown']);
		$this->assertSame('Handhavingsdossier', $byId['handhaving']['label']);
		$this->assertTrue($byId['handhaving']['shown']);
		// A hidden type never drops off the list, even when nothing else names it.
		$this->assertSame('Afgeschaft', $byId['afgeschaft']['label']);
		$this->assertFalse($byId['afgeschaft']['shown']);
	}//end testHiddenTypeStaysListed()

	/**
	 * Saving writes the normalised list onto the portal, which fits the real
	 * register schema; showing a type again removes it, and nothing else on
	 * the portal changes (REQ-OSC-003).
	 *
	 * @spec openspec/changes/operate-show-per-case-type/specs/portal-case-type-visibility/spec.md#requirement-nothing-is-deleted-by-hiding-req-osc-003
	 */
	public function testSavingWritesOnlyThePortalsList(): void {
		$uuid = $this->seedPortal(hidden: []);
		$catalogue = $this->catalogue();

		$saved = $catalogue->save(
			portal: $this->portal(),
			hidden: [
				['register' => 'dossiq', 'schema' => 'caseType', 'typeId' => 'handhaving', 'label' => 'Handhavingsdossier', 'shown' => false],
				['typeId' => 'handhaving'],
				['typeId' => ''],
				'junk',
			]
		);

		$this->assertNotNull($saved);
		$this->assertSame(
			[['register' => 'dossiq', 'schema' => 'caseType', 'typeId' => 'handhaving', 'label' => 'Handhavingsdossier']],
			$this->rows[$uuid]['hiddenCaseTypes']
		);
		$this->assertSame('Mijn Alkmaar', $this->rows[$uuid]['title']);
		$this->assertValidPortal($this->rows[$uuid]);

		$catalogue->save(portal: $this->portal(), hidden: []);
		$this->assertSame([], $this->rows[$uuid]['hiddenCaseTypes']);
	}//end testSavingWritesOnlyThePortalsList()

	public function testAnUnknownPortalIsNotFound(): void {
		$this->assertNull($this->catalogue()->portalBySlug(slug: 'nergens'));
		$this->assertNull($this->catalogue()->portalBySlug(slug: ''));
	}//end testAnUnknownPortalIsNotFound()

	/**
	 * The stored portal, as the catalogue reads it by slug.
	 *
	 * @return array<string, mixed>
	 */
	private function portal(): array {
		$portal = $this->catalogue()->portalBySlug(slug: 'mijn-alkmaar');
		$this->assertNotNull($portal);

		return $portal;
	}//end portal()

	/**
	 * Put the portal in the fake store.
	 *
	 * @param array<int, array<string, string>> $hidden Its hidden case types.
	 *
	 * @return string The uuid.
	 */
	private function seedPortal(array $hidden): string {
		return $this->seedRow('portal', [
			'title' => 'Mijn Alkmaar',
			'slug' => 'mijn-alkmaar',
			'status' => 'published',
			'organisation' => 'alkmaar',
			'hiddenCaseTypes' => $hidden,
		]);
	}//end seedPortal()

	/**
	 * The catalogue over the fake store, a case app declaring its case type
	 * source, and a case type reader that knows two types.
	 *
	 * @return PortalCaseTypeCatalogue
	 */
	private function catalogue(): PortalCaseTypeCatalogue {
		$registry = $this->getMockBuilder(PortalContributionRegistry::class)
			->disableOriginalConstructor()
			->onlyMethods(['servedAudiences', 'aggregateFor'])
			->getMock();
		$registry->method('servedAudiences')->willReturn(['client']);
		$registry->method('aggregateFor')->willReturn([
			'contributions' => [[
				'app' => 'dossiq',
				'collections' => [
					['id' => 'cases', 'kind' => 'cases', 'register' => 'dossiq', 'schema' => 'case', 'caseTypeSource' => ['register' => 'dossiq', 'schema' => 'caseType', 'labelField' => 'title']],
					['id' => 'notes', 'kind' => 'inbox', 'register' => 'dossiq', 'schema' => 'note', 'caseTypeSource' => ['register' => 'x', 'schema' => 'y']],
				],
			]],
		]);

		$caseTypes = $this->getMockBuilder(CaseTypeReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCaseType', 'listCaseTypes'])
			->getMock();
		$caseTypes->method('readCaseType')->willReturnCallback(
			static fn (string $register, string $schema, string $id): ?array => ($id === 'omgevingsvergunning' ? ['id' => $id, 'title' => 'Omgevingsvergunning'] : null)
		);
		$caseTypes->method('listCaseTypes')->willReturnCallback(
			static fn (string $register, string $schema): array => (($register === 'dossiq' && $schema === 'caseType')
				? [['id' => 'omgevingsvergunning', 'title' => 'Omgevingsvergunning'], ['id' => 'handhaving', 'title' => 'Handhavingsdossier']]
				: [])
		);

		$portals = $this->createMock(PortalResolver::class);
		$reader = $this->fakeReader();

		return new PortalCaseTypeCatalogue(
			$reader,
			$this->fakeWriter(),
			new PortalFormBindingResolver($reader),
			$caseTypes,
			new CaseTypeVisibility($portals),
			$registry
		);
	}//end catalogue()

	/**
	 * Assert a portal row fits the real `portal` schema.
	 *
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return void
	 */
	private function assertValidPortal(array $row): void {
		unset($row['uuid'], $row['_schema']);
		$register = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/portaliq_register.json'), true);
		$schema = $register['components']['schemas']['portal'];
		$jsonSchema = json_decode(
			(string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $schema['properties']]),
			false
		);
		$result = (new Validator())->validate(json_decode((string)json_encode($row), false), $jsonSchema);
		$this->assertTrue($result->isValid(), 'the portal fits the register schema');
	}//end assertValidPortal()
}//end class
