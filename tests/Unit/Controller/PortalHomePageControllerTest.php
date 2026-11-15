<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PortalHomePageController;
use OCA\Portaliq\Service\Cms\PortalHomePage;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * portaliq-cms: the home-page report is reachable, is reachable by an
 * administrator only, and answers what the classification found.
 *
 * The posture matters more here than on most admin reads: the answer says
 * whether a DRAFT page sits at a route, which is the existence oracle the
 * public content API withholds on purpose.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
class PortalHomePageControllerTest extends TestCase {

	/**
	 * The route table names this controller's method, so the widget's address
	 * reaches a controller rather than Nextcloud's 404 page.
	 */
	public function testTheRouteNamesTheOwnersMethod(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$named = [];
		foreach ((array)($routes['routes'] ?? []) as $route) {
			$named[(string)$route['name']] = (string)$route['verb'] . ' ' . (string)$route['url'];
		}

		$this->assertSame('GET /api/portals/{slug}/home-page', ($named['portalHomePage#index'] ?? ''));
		$this->assertTrue(method_exists(PortalHomePageController::class, 'index'));
	}//end testTheRouteNamesTheOwnersMethod()

	/**
	 * Nextcloud lets only an administrator through a method that carries no
	 * opt-out attribute, so none may be here.
	 */
	public function testNonAdminIsRefused(): void {
		$method = (new ReflectionClass(PortalHomePageController::class))->getMethod('index');

		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertEmpty($method->getAttributes(NoCSRFRequired::class));
	}//end testNonAdminIsRefused()

	public function testTheReportAnswersTheClassification(): void {
		$verdict = ['state' => 'draft', 'route' => '/', 'pageId' => 'page-draft', 'pageTitle' => 'Welkom'];

		$response = $this->controller($verdict)->index(slug: 'wilgenboom');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($verdict, $response->getData()['homePage']);
	}//end testTheReportAnswersTheClassification()

	/**
	 * The slug the administrator's page is showing is the slug asked about.
	 */
	public function testThePortalAskedAboutIsTheOneInTheAddress(): void {
		$homePage = $this->getMockBuilder(PortalHomePage::class)
			->disableOriginalConstructor()
			->onlyMethods(['verdict'])
			->getMock();
		$homePage->expects($this->once())
			->method('verdict')
			->with('wilgenboom')
			->willReturn(['state' => 'missing', 'route' => '/', 'pageId' => null, 'pageTitle' => null]);

		$controller = new PortalHomePageController($this->createMock(IRequest::class), $homePage);

		$this->assertSame('missing', $controller->index(slug: 'wilgenboom')->getData()['homePage']['state']);
	}//end testThePortalAskedAboutIsTheOneInTheAddress()

	/**
	 * A controller over a fixed classification.
	 *
	 * @param array<string, mixed> $verdict The classification to answer.
	 *
	 * @return PortalHomePageController
	 */
	private function controller(array $verdict): PortalHomePageController {
		$homePage = $this->getMockBuilder(PortalHomePage::class)
			->disableOriginalConstructor()
			->onlyMethods(['verdict'])
			->getMock();
		$homePage->method('verdict')->willReturn($verdict);

		return new PortalHomePageController($this->createMock(IRequest::class), $homePage);
	}//end controller()
}//end class
