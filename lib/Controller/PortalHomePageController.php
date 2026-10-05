<?php

/**
 * Portaliq portal home page controller
 *
 * The admin read behind the "Home page" report on a portal's own page: does
 * this portal have a published page at its root, and if not, which of the two
 * configuration errors applies.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Cms\PortalHomePage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Reports whether one portal has a home page.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
 */
class PortalHomePageController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalHomePage $homePage The portal's root-route state.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalHomePage $homePage,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Whether the portal has a published page at its root.
	 *
	 * An unknown slug answers `missing`, the same as a known portal with no
	 * page at `/`. That is deliberate: the caller is the administrator's own
	 * portal page, which already holds the portal, and resolving the portal
	 * again would add a read that changes no answer this report gives.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse `homePage`: the state, the route, and the page it
	 *                      names when there is one.
	 *
	 * @auth admin-only This read says whether a DRAFT page exists at a route,
	 *       which is the existence oracle the public content API withholds.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-without-a-published-home-page-must-be-reported-as-a-configuration-error
	 */
	public function index(string $slug): JSONResponse {
		return new JSONResponse(['homePage' => $this->homePage->verdict(slug: $slug)]);
	}//end index()
}//end class
