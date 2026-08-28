<?php

/**
 * Portaliq CMS Editor Controller
 *
 * The one thing the public site needs from an authenticated context: whether
 * the visitor looking at this page may edit it, and where the editor is.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\CmsReader;
use OCA\Portaliq\Service\PageEditorService;
use OCA\Portaliq\Service\PortalResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Answers "may I edit what I am looking at, and where".
 *
 * THIS IS NOT THE GUARD ON EDITING. The designer writes pages through
 * OpenRegister's object API, where the `page` schema's authorization block
 * decides. What this endpoint decides is whether to OFFER the editing — and
 * the two answers come from one predicate ({@see PageEditorService::mayEdit()})
 * precisely so an offer that leads to a refusal is a bug in one place rather
 * than a disagreement between two.
 *
 * IT IS ALSO NOT AN EXISTENCE ORACLE. A caller who may not edit is told only
 * that, and is never told whether a page exists at the route they asked about —
 * the public content API already refuses to distinguish an unpublished page
 * from a route that never existed, and an editing probe that leaked the
 * difference would hand back what that refusal protects.
 *
 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
 */
class CmsEditorController extends Controller {


	/**
	 * Constructor.
	 *
	 * @param string            $appName    The app id.
	 * @param IRequest          $request    The request.
	 * @param PortalResolver    $resolver   Resolves the serving portal, exactly as the content API does.
	 * @param CmsReader         $reader     Resolves a route to the page behind it.
	 * @param PageEditorService $pageEditor Decides whether this caller may edit.
	 * @param IURLGenerator     $urls       Builds the links into the app.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly CmsReader $reader,
		private readonly PageEditorService $pageEditor,
		private readonly IURLGenerator $urls,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()


	/**
	 * The editing context for one route of the serving portal.
	 *
	 * @param string|null $route  The in-site route being viewed, leading slash optional.
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 *
	 * @return JSONResponse `{canEdit}` always; the page and its editor only for an editor.
	 *
	 * @spec openspec/changes/portal-page-designer/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function editingContext(?string $route = null, ?string $portal = null): JSONResponse {
		// THE AUTHORIZATION GUARD, and it comes first for a reason: everything
		// below reads content with RBAC bypassed, including unpublished pages.
		if ($this->pageEditor->mayEdit() === false) {
			return $this->uncacheable(payload: ['canEdit' => false]);
		}

		$resolved = $this->resolver->resolve(request: $this->request, portalSlug: $portal);
		if ($resolved === null) {
			// An editor on a host that resolves to no portal can still be sent
			// to the page list — there is simply no page to name.
			return $this->uncacheable(payload: $this->context(pageId: null));
		}

		$normalised = '/'.ltrim((string)($route ?? ''), '/');
		$pageId = $this->reader->identify(portal: (string)$resolved['slug'], route: $normalised);

		return $this->uncacheable(payload: $this->context(pageId: $pageId));
	}//end editingContext()


	/**
	 * The editor's context payload.
	 *
	 * @param string|null $pageId The page at the requested route, when there is one.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function context(?string $pageId): array {
		$payload = [
			'canEdit'     => true,
			'pageId'      => $pageId,
			'pagesUrl'    => $this->urls->linkToRoute('portaliq.dashboard.catchAll', ['path' => 'pages']),
			'newPageUrl'  => $this->urls->linkToRoute('portaliq.dashboard.catchAll', ['path' => 'pages']).'?new=page',
			'designerUrl' => null,
		];

		if ($pageId !== null) {
			$payload['designerUrl'] = $this->urls->linkToRoute(
				'portaliq.dashboard.catchAll',
				['path' => 'pages/'.$pageId.'/layout']
			);
		}

		return $payload;
	}//end context()


	/**
	 * A response that must never be cached.
	 *
	 * The answer is per-USER — it is the difference between a page that offers
	 * editing and one that does not. A shared cache in front of this endpoint
	 * holding one visitor's `canEdit: true` would hand every anonymous visitor
	 * an editing control, and the control's own links with it.
	 *
	 * @param array<string, mixed> $payload The response body.
	 *
	 * @return JSONResponse The response.
	 */
	private function uncacheable(array $payload): JSONResponse {
		$response = new JSONResponse($payload);
		$response->addHeader('Cache-Control', 'private, no-store');

		return $response;
	}//end uncacheable()
}//end class
