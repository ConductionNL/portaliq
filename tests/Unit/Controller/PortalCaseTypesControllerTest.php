<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalCaseTypesController;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Service\CaseTypeColumns\CaseTypeColumnSettings;
use OCA\Portaliq\Service\PortalCaseTypeCatalogue;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * operate-show-per-case-type REQ-OSC-001: only an administrator reaches the
 * "Case types" routes; the list keeps a hidden type, and a save answers the
 * list as stored.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
class PortalCaseTypesControllerTest extends TestCase {
	private const PORTAL = ['uuid' => 'p-1', 'slug' => 'mijn-alkmaar', 'title' => 'Mijn Alkmaar', 'status' => 'published'];

	/**
	 * Nextcloud lets only an administrator through a method that carries no
	 * opt-out attribute, so none may be here.
	 */
	public function testNonAdminIsRefused(): void {
		$class = new ReflectionClass(PortalCaseTypesController::class);
		foreach (['index', 'update', 'updateColumns'] as $name) {
			$method = $class->getMethod($name);
			$this->assertEmpty($method->getAttributes(PublicPage::class), $name);
			$this->assertEmpty($method->getAttributes(NoAdminRequired::class), $name);
			$this->assertEmpty($method->getAttributes(NoCSRFRequired::class), $name);
		}
	}//end testNonAdminIsRefused()

	public function testHiddenTypeStaysListed(): void {
		$catalogue = $this->catalogue();
		$catalogue->method('listFor')->willReturn([
			['register' => 'oud', 'schema' => 'zaaktype', 'typeId' => 'afgeschaft', 'label' => 'Afgeschaft', 'shown' => false],
		]);

		$response = ($this->controller($catalogue))->index(slug: 'mijn-alkmaar');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('afgeschaft', $response->getData()['caseTypes'][0]['typeId']);
		$this->assertFalse($response->getData()['caseTypes'][0]['shown']);
	}//end testHiddenTypeStaysListed()

	public function testAnUnknownPortalIs404(): void {
		$catalogue = $this->getMockBuilder(PortalCaseTypeCatalogue::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'listFor', 'save'])
			->getMock();
		$catalogue->method('portalBySlug')->willReturn(null);
		$catalogue->expects($this->never())->method('save');
		$controller = $this->controller($catalogue);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->index(slug: 'nergens')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->update(slug: 'nergens', hiddenCaseTypes: [])->getStatus());
	}//end testAnUnknownPortalIs404()

	public function testASaveAnswersTheStoredList(): void {
		$catalogue = $this->catalogue();
		$catalogue->expects($this->once())->method('save')
			->with(self::PORTAL, [['typeId' => 'handhaving']])
			->willReturn(self::PORTAL + ['hiddenCaseTypes' => [['typeId' => 'handhaving']]]);
		$catalogue->method('listFor')->willReturnCallback(
			static fn (array $portal): array => [['typeId' => 'handhaving', 'shown' => ($portal['hiddenCaseTypes'] ?? []) === []]]
		);

		$response = ($this->controller($catalogue))
			->update(slug: 'mijn-alkmaar', hiddenCaseTypes: [['typeId' => 'handhaving']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['caseTypes'][0]['shown']);
	}//end testASaveAnswersTheStoredList()

	public function testAFailedSaveIs502(): void {
		$catalogue = $this->catalogue();
		$catalogue->method('save')->willReturn(null);

		$response = ($this->controller($catalogue))
			->update(slug: 'mijn-alkmaar', hiddenCaseTypes: []);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
	}//end testAFailedSaveIs502()

	public function testTheListCarriesTheColumnsAndTheFields(): void {
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('servedAudiences')->willReturn(['client']);
		$registry->method('aggregateFor')->willReturn(
			['contributions' => [['collections' => [['kind' => 'cases', 'columns' => [['field' => 'bewijs', 'label' => 'Vergunningsbewijs'], ['field' => 'bewijs']]]]]]]
		);
		$portal = self::PORTAL + ['caseTypeColumns' => ['parkeren' => [['field' => 'bewijs', 'render' => 'qr']]]];
		$catalogue = $this->getMockBuilder(PortalCaseTypeCatalogue::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'listFor', 'save'])
			->getMock();
		$catalogue->method('portalBySlug')->willReturn($portal);
		$catalogue->method('listFor')->willReturn([]);

		$data = $this->controller($catalogue, ['columns' => new CaseTypeColumnSettings($this->createMock(PortalObjectWriter::class), $registry)])
			->index(slug: 'mijn-alkmaar')->getData();

		$this->assertSame([['field' => 'bewijs', 'render' => 'qr']], ((array)$data['columns'])['parkeren']);
		$this->assertSame([['field' => 'bewijs', 'label' => 'Vergunningsbewijs']], $data['fields']);
	}//end testTheListCarriesTheColumnsAndTheFields()

	public function testSavingOneTypesColumnsKeepsTheOtherTypes(): void {
		$stored = self::PORTAL + ['caseTypeColumns' => ['afval' => [['field' => 'adres', 'render' => 'text']], 'parkeren' => [['field' => 'oud', 'render' => 'text']]]];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->expects($this->once())->method('updateObject')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, \Closure $data): array => array_merge($stored, $data($stored))
		);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('columns')->willReturn([['field' => 'bewijs', 'render' => 'qr', 'linkLabel' => 'Toevoegen aan wallet'], ['field' => 'x', 'render' => 'badge']]);

		$response = $this->controller($this->catalogue(), ['columns' => new CaseTypeColumnSettings($writer, $this->createMock(PortalContributionRegistry::class)), 'request' => $request])
			->updateColumns(slug: 'mijn-alkmaar', typeId: 'parkeren');

		$columns = (array)$response->getData()['columns'];
		$this->assertSame([['field' => 'adres', 'render' => 'text']], $columns['afval']);
		$this->assertSame([['field' => 'bewijs', 'render' => 'qr', 'linkLabel' => 'Toevoegen aan wallet']], $columns['parkeren']);
	}//end testSavingOneTypesColumnsKeepsTheOtherTypes()

	public function testAnEmptyListRemovesTheType(): void {
		$stored = self::PORTAL + ['caseTypeColumns' => ['parkeren' => [['field' => 'oud', 'render' => 'text']]]];
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturnCallback(
			static fn (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, \Closure $data): array => array_merge($stored, ['caseTypeColumns' => (array)$data($stored)['caseTypeColumns']])
		);

		$response = $this->controller($this->catalogue(), ['columns' => new CaseTypeColumnSettings($writer, $this->createMock(PortalContributionRegistry::class))])
			->updateColumns(slug: 'mijn-alkmaar', typeId: 'parkeren');

		$this->assertSame([], (array)$response->getData()['columns']);
	}//end testAnEmptyListRemovesTheType()

	public function testAColumnsSaveOnAnUnknownPortalOrAFailedWrite(): void {
		$writer = $this->createMock(PortalObjectWriter::class);
		$writer->method('updateObject')->willReturn(null);
		$settings = new CaseTypeColumnSettings($writer, $this->createMock(PortalContributionRegistry::class));
		$missing  = $this->getMockBuilder(PortalCaseTypeCatalogue::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'listFor', 'save'])
			->getMock();
		$missing->method('portalBySlug')->willReturn(null);

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller($missing, ['columns' => $settings])->updateColumns(slug: 'nergens', typeId: 'parkeren')->getStatus());
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $this->controller($this->catalogue(), ['columns' => $settings])->updateColumns(slug: 'mijn-alkmaar', typeId: 'parkeren')->getStatus());
	}//end testAColumnsSaveOnAnUnknownPortalOrAFailedWrite()

	/**
	 * The controller over a catalogue double.
	 *
	 * @param PortalCaseTypeCatalogue $catalogue The catalogue.
	 * @param array{columns?: CaseTypeColumnSettings, request?: IRequest} $with Real collaborators; doubles for the rest.
	 *
	 * @return PortalCaseTypesController
	 */
	private function controller(PortalCaseTypeCatalogue $catalogue, array $with = []): PortalCaseTypesController {
		return new PortalCaseTypesController(
			($with['request'] ?? $this->createMock(IRequest::class)),
			$catalogue,
			($with['columns'] ?? new CaseTypeColumnSettings($this->createMock(PortalObjectWriter::class), $this->createMock(PortalContributionRegistry::class)))
		);
	}//end controller()

	/**
	 * A catalogue double that finds the portal.
	 *
	 * @return PortalCaseTypeCatalogue&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function catalogue(): PortalCaseTypeCatalogue {
		$catalogue = $this->getMockBuilder(PortalCaseTypeCatalogue::class)
			->disableOriginalConstructor()
			->onlyMethods(['portalBySlug', 'listFor', 'save'])
			->getMock();
		$catalogue->method('portalBySlug')->willReturn(self::PORTAL);

		return $catalogue;
	}//end catalogue()
}//end class
