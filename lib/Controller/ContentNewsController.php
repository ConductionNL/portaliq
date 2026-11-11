<?php

/**
 * Portaliq Content News Controller
 *
 * The public news of one portal, on the headless content contract
 * (site-school-blocks): the list a website's news widget shows, and one item
 * for the article page. Public like the rest of `/api/content`, gated by the
 * portal's declared sign-in modes the same way, and cacheable for a visitor
 * who is not signed in.
 *
 * Which items, and what of them, is PublicNewsReader's decision: published,
 * put on this portal's website by staff, never an item about single
 * children, and only photos from this portal's own published media library.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Service\PortalResolver;
use OCA\Portaliq\Service\PublicNewsReader;
use OCA\Portaliq\Service\Session\BearerSessionResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Serves a portal's public news.
 *
 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
 */
class ContentNewsController extends Controller {
	use PublicContentGate;
	use BearerCachedJson;

	/**
	 * Constructor.
	 *
	 * @param string               $appName  The app id.
	 * @param IRequest             $request  The request.
	 * @param PortalResolver       $resolver Resolves the serving portal.
	 * @param BearerSessionResolver $session  Resolves the caller's portal session for the content gate.
	 * @param PublicNewsReader     $news     Reads the portal's public news.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PortalResolver $resolver,
		private readonly BearerSessionResolver $session,
		private readonly PublicNewsReader $news,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The newest public items of the resolved portal.
	 *
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 * @param int|null    $limit  How many, 1 to 12; 4 when absent.
	 *
	 * @return JSONResponse `{items: []}`, a 401/403 refusal, or 404.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function index(?string $portal = null, ?int $limit = null): JSONResponse {
		$resolved = $this->permittedPortal(portal: $portal);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		return $this->publicJson(
			payload: ['items' => $this->news->listFor(portal: (string)$resolved['slug'], limit: ($limit ?? 4))]
		);
	}//end index()

	/**
	 * One public item of the resolved portal.
	 *
	 * @param string      $id     The item id.
	 * @param string|null $portal Explicit portal slug, for a consumer not using the host.
	 *
	 * @return JSONResponse `{item}`, a 401/403 refusal, or 404 for anything that is not a public item of this portal.
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-news-item-shows-on-a-portals-public-website-only-when-staff-put-it-there
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 240, period: 60)]
	public function show(string $id, ?string $portal = null): JSONResponse {
		$resolved = $this->permittedPortal(portal: $portal);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		$item = $this->news->itemFor(portal: (string)$resolved['slug'], id: $id);
		if ($item === null) {
			return $this->notFound();
		}

		return $this->publicJson(payload: ['item' => $item]);
	}//end show()
}//end class
