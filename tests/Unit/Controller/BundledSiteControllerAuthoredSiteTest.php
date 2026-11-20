<?php

/**
 * Tests for BundledSiteController once a bundle's paths are moved to an
 * authored portal.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\BundledSiteController;
use OCA\Portaliq\Service\RetiredBundleAddress;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The bundle's paths redirect to the authored site only once a portal is named.
 *
 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
 */
#[CoversClass(BundledSiteController::class)]
#[UsesClass(RetiredBundleAddress::class)]
final class BundledSiteControllerAuthoredSiteTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * The URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator&MockObject $urlGenerator;

	/**
	 * The controller under test.
	 *
	 * @var BundledSiteController
	 */
	private BundledSiteController $controller;

	/**
	 * Build the controller with mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $name, array $params = []): string => match ($name) {
				'portaliq.portalPage.site' => '/index.php/apps/portaliq/site',
				'portaliq.portalPage.sitePath' => '/index.php/apps/portaliq/site/' . $params['path'],
				default => '/unexpected/' . $name,
			}
		);

		$this->controller = new BundledSiteController('portaliq', $this->request, $this->appConfig, $this->urlGenerator);
	}//end setUp()

	/**
	 * Name the portal the woo bundle's paths moved to.
	 *
	 * @param string $portal The portal slug, '' for none.
	 *
	 * @return void
	 */
	private function movedTo(string $portal): void {
		$this->appConfig->expects($this->atLeastOnce())
			->method('getValueString')
			->with('portaliq', 'bundled_site_portal_woo', '')
			->willReturn($portal);
	}//end movedTo()

	/**
	 * Without a portal named, the bundle still serves its own paths.
	 *
	 * @return void
	 */
	public function testWithoutAPortalTheBundleStillServes(): void {
		$this->movedTo(portal: '  ');
		$this->urlGenerator->expects($this->never())->method('linkToRoute');

		$this->assertInstanceOf(DataDisplayResponse::class, $this->controller->serve(bundle: 'woo'));
		$this->assertInstanceOf(DataDisplayResponse::class, $this->controller->servePath(bundle: 'woo', path: 'zoeken'));
	}//end testWithoutAPortalTheBundleStillServes()

	/**
	 * The bundle's home lands on the site's home of the named portal, with 302.
	 *
	 * @return void
	 */
	public function testTheHomeRedirectsToTheSiteOfThePortal(): void {
		$this->movedTo(portal: 'open-publicaties');
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/portaliq/woo');

		$response = $this->controller->serve(bundle: 'woo');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(302, $response->getStatus());
		$this->assertSame('/index.php/apps/portaliq/site?portal=open-publicaties', $response->getRedirectURL());
	}//end testTheHomeRedirectsToTheSiteOfThePortal()

	/**
	 * A publication keeps its id and the search keeps its query.
	 *
	 * @return void
	 */
	public function testAPublicationKeepsItsIdAndTheQueryIsKept(): void {
		$this->movedTo(portal: 'open-publicaties');
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/portaliq/woo/publicatie/abc-123?q=begroting&page=2');

		$response = $this->controller->servePath(bundle: 'woo', path: 'publicatie/abc-123');

		$this->assertSame(
			'/index.php/apps/portaliq/site/publicatie/abc-123?q=begroting&page=2&portal=open-publicaties',
			$response->getRedirectURL()
		);
	}//end testAPublicationKeepsItsIdAndTheQueryIsKept()

	/**
	 * A portal the request already names is not overridden.
	 *
	 * @return void
	 */
	public function testAPortalInTheQueryIsKept(): void {
		$this->movedTo(portal: 'open-publicaties');
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/portaliq/woo/zoeken?portal=andere');

		$response = $this->controller->servePath(bundle: 'woo', path: 'zoeken');

		$this->assertSame('/index.php/apps/portaliq/site/zoeken?portal=andere', $response->getRedirectURL());
	}//end testAPortalInTheQueryIsKept()

	/**
	 * Sign-in goes to the own area, an administration screen to the home.
	 *
	 * @return void
	 */
	public function testSignInAndAdministrationLandOnTheirNewPlaces(): void {
		$this->movedTo(portal: 'open-publicaties');
		$this->request->method('getRequestUri')->willReturn('/index.php/apps/portaliq/woo/x');

		$this->assertSame(
			'/index.php/apps/portaliq/site/mijn?portal=open-publicaties',
			$this->controller->servePath(bundle: 'woo', path: 'login')->getRedirectURL()
		);
		$this->assertSame(
			'/index.php/apps/portaliq/site?portal=open-publicaties',
			$this->controller->servePath(bundle: 'woo', path: 'beheer/applicaties')->getRedirectURL()
		);
	}//end testSignInAndAdministrationLandOnTheirNewPlaces()

	/**
	 * A bundle this app does not ship is still not found, setting or not.
	 *
	 * @return void
	 */
	public function testAnUnknownBundleIsNotRedirected(): void {
		$this->appConfig->expects($this->never())->method('getValueString');

		$response = $this->controller->servePath(bundle: 'lib', path: 'zoeken');

		$this->assertSame(404, $response->getStatus());
	}//end testAnUnknownBundleIsNotRedirected()
}//end class
