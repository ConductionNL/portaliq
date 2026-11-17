<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalNavigationController;
use OCA\Portaliq\Kernel\Contracts\PortalNavigationCatalogueInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * operate-pages-per-portal-and-client REQ-PGC-003: only an administrator
 * reaches the "Navigation" routes, and a save answers the list as stored.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
class PortalNavigationControllerTest extends TestCase {
	private const PORTAL = ['uuid' => 'p-1', 'slug' => 'open-tilburg'];

	private PortalNavigationCatalogueInterface&MockObject $catalogue;

	protected function setUp(): void {
		$this->catalogue = $this->createMock(PortalNavigationCatalogueInterface::class);
	}//end setUp()

	private function controller(mixed $navigation = null): PortalNavigationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['navigation', null, $navigation]]);

		return new PortalNavigationController(request: $request, catalogue: $this->catalogue);
	}//end controller()

	/**
	 * Nextcloud lets only an administrator through a method that carries no
	 * opt-out attribute, so none may be here.
	 */
	public function testNonAdminIsRefused(): void {
		$class = new ReflectionClass(PortalNavigationController::class);
		foreach (['index', 'update'] as $name) {
			$method = $class->getMethod($name);
			$this->assertEmpty($method->getAttributes(PublicPage::class), $name);
			$this->assertEmpty($method->getAttributes(NoAdminRequired::class), $name);
			$this->assertEmpty($method->getAttributes(NoCSRFRequired::class), $name);
		}
	}//end testNonAdminIsRefused()

	public function testTheListIsAnsweredPerAudience(): void {
		$this->catalogue->method('portalBySlug')->with('open-tilburg')->willReturn(self::PORTAL);
		$this->catalogue->method('listFor')->with(self::PORTAL)
			->willReturn([['audience' => 'client', 'pages' => [['page' => 'pipelinq:quotes', 'hidden' => true]]]]);

		$response = $this->controller()->index(slug: 'open-tilburg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('pipelinq:quotes', $response->getData()['audiences'][0]['pages'][0]['page']);
	}//end testTheListIsAnsweredPerAudience()

	public function testAnUnknownPortalIs404(): void {
		$this->catalogue->method('portalBySlug')->willReturn(null);
		$this->catalogue->expects($this->never())->method('save');

		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->index(slug: 'nergens')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller(['client' => []])->update(slug: 'nergens')->getStatus());
	}//end testAnUnknownPortalIs404()

	public function testANavigationThatIsNotAnObjectIs400(): void {
		$this->catalogue->method('portalBySlug')->willReturn(self::PORTAL);
		$this->catalogue->expects($this->never())->method('save');

		$response = $this->controller('client')->update(slug: 'open-tilburg');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_navigation', $response->getData()['error']);
	}//end testANavigationThatIsNotAnObjectIs400()

	public function testASaveAnswersTheStoredList(): void {
		$navigation = ['client' => [['page' => 'pipelinq:quotes', 'hidden' => true]]];
		$saved      = self::PORTAL + ['navigation' => $navigation];
		$this->catalogue->method('portalBySlug')->willReturn(self::PORTAL);
		$this->catalogue->expects($this->once())->method('save')->with(self::PORTAL, $navigation)->willReturn($saved);
		$this->catalogue->expects($this->once())->method('listFor')->with($saved)
			->willReturn([['audience' => 'client', 'pages' => [['page' => 'pipelinq:quotes', 'hidden' => true]]]]);

		$response = $this->controller($navigation)->update(slug: 'open-tilburg');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['audiences'][0]['pages'][0]['hidden']);
	}//end testASaveAnswersTheStoredList()

	public function testAFailedWriteIs502(): void {
		$this->catalogue->method('portalBySlug')->willReturn(self::PORTAL);
		$this->catalogue->method('save')->willReturn(null);

		$response = $this->controller(['client' => []])->update(slug: 'open-tilburg');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
	}//end testAFailedWriteIs502()
}//end class
