<?php

/**
 * Portaliq portal case types controller
 *
 * The admin routes behind a portal's "Case types" page: which case types the
 * portal can name, whether each is shown, and the save of the hidden list.
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
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalCaseTypeCatalogue;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Lists and saves the case types one portal shows.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
 */
class PortalCaseTypesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param PortalCaseTypeCatalogue $catalogue The portal's case types.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalCaseTypeCatalogue $catalogue,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every case type the portal can name, each with whether it is shown.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return JSONResponse `caseTypes`, or 404 for an unknown portal.
	 *
	 * @auth admin-only Which case types residents see is an administrator's
	 *       choice. Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 */
	public function index(string $slug): JSONResponse {
		$portal = $this->catalogue->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['caseTypes' => $this->catalogue->listFor(portal: $portal)]);
	}//end index()

	/**
	 * Save the case types the portal does not show.
	 *
	 * @param string $slug The portal slug.
	 * @param array<int, mixed> $hiddenCaseTypes The case types to hide.
	 *
	 * @return JSONResponse The list as stored, 404 for an unknown portal, or
	 *                      502 when the write failed.
	 *
	 * @auth admin-only Which case types residents see is an administrator's
	 *       choice. Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-an-administrator-hides-a-case-type-in-one-portal-req-osc-001
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-nothing-is-deleted-by-hiding-req-osc-003
	 */
	public function update(string $slug, array $hiddenCaseTypes = []): JSONResponse {
		$portal = $this->catalogue->portalBySlug(slug: $slug);
		if ($portal === null) {
			return new JSONResponse(['error' => 'portal_not_found'], Http::STATUS_NOT_FOUND);
		}

		$saved = $this->catalogue->save(portal: $portal, hidden: $hiddenCaseTypes);
		if ($saved === null) {
			return new JSONResponse(['error' => 'save_failed'], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['caseTypes' => $this->catalogue->listFor(portal: $saved)]);
	}//end update()
}//end class
