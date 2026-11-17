<?php

/**
 * Portaliq portal navigation controller
 *
 * The admin routes behind the "Navigation" section of a portal's page: per
 * audience the pages the installed apps contribute, in the portal's order and
 * with whether it hides each, and the save of that choice.
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
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\Kernel\AppIdentity;
use OCA\Portaliq\Kernel\Contracts\PortalNavigationCatalogueInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists and saves the pages one portal shows, per audience.
 *
 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
 */
class PortalNavigationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                           $request   The request.
	 * @param PortalNavigationCatalogueInterface $catalogue The portal's navigation.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalNavigationCatalogueInterface $catalogue,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Per audience, the pages the installed apps contribute, in the portal's
	 * order, each with whether the portal hides it.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse `audiences`, or 404 for an unknown portal.
	 *
	 * @auth admin-only Which pages a portal's menu shows is an administrator's
	 *       choice. Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function index(string $slug): JSONResponse {
		$portal = $this->catalogue->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['audiences' => $this->catalogue->listFor(portal: $portal)]);
	}//end index()

	/**
	 * Save the portal's navigation: per audience, the pages in the chosen
	 * order with whether each is hidden.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse The list as stored, 404 for an unknown portal, 400
	 *                      when `navigation` is not an object, or 502 when the
	 *                      write failed.
	 *
	 * @auth admin-only Which pages a portal's menu shows is an administrator's
	 *       choice. Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-the-administrator-makes-the-portal-choice-on-the-portals-page-req-pgc-003
	 */
	public function update(string $slug): JSONResponse {
		$portal = $this->catalogue->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$navigation = $this->request->getParam('navigation', null);
		if (is_array($navigation) === false) {
			return new JSONResponse(['error' => 'invalid_navigation'], Http::STATUS_BAD_REQUEST);
		}

		$saved = $this->catalogue->save(portal: $portal, navigation: $navigation);
		if ($saved === null) {
			return new JSONResponse(['error' => 'save_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['audiences' => $this->catalogue->listFor(portal: $saved)]);
	}//end update()
}//end class
