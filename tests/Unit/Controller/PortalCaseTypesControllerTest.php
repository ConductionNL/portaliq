<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalCaseTypesController;
use OCA\Portaliq\Service\PortalCaseTypeCatalogue;
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
		foreach (['index', 'update'] as $name) {
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

		$response = (new PortalCaseTypesController($this->createMock(IRequest::class), $catalogue))->index(slug: 'mijn-alkmaar');

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
		$controller = new PortalCaseTypesController($this->createMock(IRequest::class), $catalogue);

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

		$response = (new PortalCaseTypesController($this->createMock(IRequest::class), $catalogue))
			->update(slug: 'mijn-alkmaar', hiddenCaseTypes: [['typeId' => 'handhaving']]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['caseTypes'][0]['shown']);
	}//end testASaveAnswersTheStoredList()

	public function testAFailedSaveIs502(): void {
		$catalogue = $this->catalogue();
		$catalogue->method('save')->willReturn(null);

		$response = (new PortalCaseTypesController($this->createMock(IRequest::class), $catalogue))
			->update(slug: 'mijn-alkmaar', hiddenCaseTypes: []);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
	}//end testAFailedSaveIs502()

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
