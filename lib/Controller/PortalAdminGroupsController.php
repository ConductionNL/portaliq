<?php

/**
 * The Administrator groups card on a portal's own page.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Tenancy\PortalAdminGroups;
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Shows which groups administer a portal, and lets an instance administrator
 * change them.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
class PortalAdminGroupsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param PortalAdminScope  $scope   Who may administer which portal.
	 * @param PortalAdminGroups $groups  Reads and writes the portal's groups.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalAdminScope $scope,
		private readonly PortalAdminGroups $groups,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The portal's administering groups. An instance administrator also gets
	 * the groups to pick from.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse `{groups, canChange, available}`, or 404.
	 *
	 * @auth per-portal An instance administrator, or a member of one of the
	 *       portal's adminGroups; any other user gets 404 (REQ-OPO-005).
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
	 */
	#[NoAdminRequired]
	public function index(string $slug): JSONResponse {
		$portal = $this->scope->portalBySlug(slug: $slug);
		if ($portal === null || $this->scope->mayAdminister(portal: $portal) === false) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$canChange = $this->scope->mayChangeAdminGroups();
		$available = [];
		if ($canChange === true) {
			$available = $this->groups->available();
		}

		return new JSONResponse([
			'groups'    => $this->groups->listFor(portal: $portal),
			'canChange' => $canChange,
			'available' => $available,
		]);
	}//end index()

	/**
	 * Store the portal's administering groups.
	 *
	 * @param string       $slug   The portal slug.
	 * @param array<mixed> $groups The group ids.
	 *
	 * @return JSONResponse The stored list; 404 for an unknown portal; 502 when the write failed.
	 *
	 * @auth admin-only Handing out who administers a portal is the hosting
	 *       party's choice. Nextcloud expresses admin-only as the ABSENCE of an
	 *       opt-out attribute, so a non-administrator gets 403 here.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
	 */
	public function update(string $slug, array $groups = []): JSONResponse {
		$portal = $this->scope->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$saved = $this->groups->save(portal: $portal, groups: $groups);
		if ($saved === null) {
			return new JSONResponse(['error' => 'save_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse([
			'groups'    => $this->groups->listFor(portal: $saved),
			'canChange' => true,
			'available' => $this->groups->available(),
		]);
	}//end update()
}//end class
