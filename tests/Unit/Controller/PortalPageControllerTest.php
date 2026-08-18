<?php

/**
 * Portaliq PortalPageController Route Test
 *
 * The editor's document, and the two things it must not become.
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
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PageRegionsController;
use OCA\Portaliq\Controller\PortalPageController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The editor's HTTP posture.
 *
 * ASSERTED ON THE ATTRIBUTES rather than by driving the response, because what
 * matters here is not what the template renders but WHO can reach it. Building
 * the controller would need the theme resolver, the token renderer, the
 * organisation service and a URL generator to observe two attributes.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
class PortalPageControllerTest extends TestCase {

	/**
	 * THE EDITOR IS NOT PUBLIC.
	 *
	 * Its sibling `site()` is `#[PublicPage]` — a portal is for anonymous
	 * visitors — and the two live in the same class, one method apart. An
	 * editor that inherited that posture would put a page-editing interface on
	 * the open web, and it would look entirely normal doing it.
	 *
	 * @return void
	 */
	public function testTheEditorIsNotPublic(): void {
		$method = (new ReflectionClass(PortalPageController::class))->getMethod('editor');

		$this->assertEmpty(
			$method->getAttributes(PublicPage::class),
			'the editor is #[PublicPage] — a page-editing interface on the open web'
		);
	}


	/**
	 * It IS reachable by a signed-in non-admin, because it only READS.
	 *
	 * The document renders a canvas from the public content API; every write it
	 * can make goes through `PageRegionsController::update()`, which is
	 * admin-only. Requiring admin to LOOK would make previewing a portal an
	 * administrative act for no gain.
	 *
	 * @return void
	 */
	public function testTheEditorDocumentIsReachableByASignedInUser(): void {
		$method = (new ReflectionClass(PortalPageController::class))->getMethod('editor');

		$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertNotEmpty(
			$method->getAttributes(NoCSRFRequired::class),
			'a GET rendering a document needs no CSRF token'
		);
	}


	/**
	 * THE WRITE IS NOT.
	 *
	 * `update()` takes a portal slug and a route and rewrites that page. With
	 * `#[NoAdminRequired]` — which it shipped with — any authenticated user
	 * could edit any tenant's pages by naming them. Carrying no auth attribute
	 * is Nextcloud's "instance admin + CSRF" default.
	 *
	 * @return void
	 */
	public function testSavingRegionsIsAdminOnlyAndCsrfProtected(): void {
		$method = (new ReflectionClass(PageRegionsController::class))->getMethod('update');

		$this->assertEmpty($method->getAttributes(PublicPage::class));
		$this->assertEmpty(
			$method->getAttributes(NoAdminRequired::class),
			'saving takes a portal slug — NoAdminRequired lets any user rewrite any tenant\'s pages'
		);
		$this->assertEmpty(
			$method->getAttributes(NoCSRFRequired::class),
			'a first-party write must carry a request token'
		);
	}


}//end class
