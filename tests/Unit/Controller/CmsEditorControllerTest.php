<?php

/**
 * CmsEditorControllerTest
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

use OCA\Portaliq\Controller\CmsEditorController;
use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The editing probe the public site asks before offering an editing control.
 *
 * The interesting behaviour is the REFUSAL, not the success: a probe that
 * answers "no" while still naming the page behind the route hands an anonymous
 * visitor exactly what the content API refuses to tell them — whether an
 * unpublished page exists there.
 */
class CmsEditorControllerTest extends TestCase {

	/**
	 * Build the controller with doubles.
	 *
	 * @param bool        $mayEdit Whether the caller may edit.
	 * @param array|null  $portal  The resolved portal, or null.
	 * @param string|null $pageId  The page behind the route, or null.
	 *
	 * @return CmsEditorController The controller.
	 */
	private function controller(bool $mayEdit, ?array $portal = ['slug' => 'open-tilburg'], ?string $pageId = 'page-1'): CmsEditorController {
		$editor = $this->createMock(PageEditorService::class);
		$editor->method('mayEdit')->willReturn($mayEdit);

		$resolver = $this->createMock(PortalResolver::class);
		$resolver->method('resolve')->willReturn($portal);

		$reader = $this->createMock(CmsReader::class);
		$reader->method('identify')->willReturn($pageId);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $args = []) => '/apps/portaliq/'.($args['path'] ?? '')
		);

		return new CmsEditorController(
			'portaliq',
			$this->createMock(IRequest::class),
			$resolver,
			$reader,
			$editor,
			$urls
		);
	}//end controller()


	/**
	 * A caller who may not edit is told only that.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testARefusalNamesNoPage(): void {
		$response = $this->controller(mayEdit: false)->editingContext('/geheim');
		$data = $response->getData();

		$this->assertFalse($data['canEdit']);
		$this->assertArrayNotHasKey('pageId', $data, 'A refusal must not disclose whether a page exists at the route.');
		$this->assertArrayNotHasKey('designerUrl', $data);
	}//end testARefusalNamesNoPage()


	/**
	 * An editor is given the page and the way into the designer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testAnEditorGetsTheDesignerLink(): void {
		$data = $this->controller(mayEdit: true)->editingContext('/over-ons')->getData();

		$this->assertTrue($data['canEdit']);
		$this->assertSame('page-1', $data['pageId']);
		$this->assertSame('/apps/portaliq/pages/page-1/layout', $data['designerUrl']);
		$this->assertSame('/apps/portaliq/pages', $data['pagesUrl']);
	}//end testAnEditorGetsTheDesignerLink()


	/**
	 * A route with no page behind it offers the listing, not a designer.
	 *
	 * An entry that opens a designer for no page is worse than its absence:
	 * it is a control that cannot do what it says.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testARouteWithNoPageOffersNoDesigner(): void {
		$data = $this->controller(mayEdit: true, pageId: null)->editingContext('/bestaat-niet')->getData();

		$this->assertTrue($data['canEdit']);
		$this->assertNull($data['pageId']);
		$this->assertNull($data['designerUrl']);
		$this->assertSame('/apps/portaliq/pages', $data['pagesUrl']);
	}//end testARouteWithNoPageOffersNoDesigner()


	/**
	 * An editor on a host that resolves to no portal still reaches the listing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testNoResolvedPortalStillOffersTheListing(): void {
		$data = $this->controller(mayEdit: true, portal: null)->editingContext('/')->getData();

		$this->assertTrue($data['canEdit']);
		$this->assertNull($data['pageId']);
	}//end testNoResolvedPortalStillOffersTheListing()


	/**
	 * The answer is per-user, so it must never be cached.
	 *
	 * A shared cache in front of this endpoint holding one editor's
	 * `canEdit: true` would hand every anonymous visitor an editing control,
	 * and the designer links with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	public function testTheAnswerIsNeverCacheable(): void {
		foreach ([true, false] as $mayEdit) {
			$headers = $this->controller(mayEdit: $mayEdit)->editingContext('/')->getHeaders();

			$this->assertSame('private, no-store', $headers['Cache-Control'] ?? null);
		}
	}//end testTheAnswerIsNeverCacheable()
}//end class
