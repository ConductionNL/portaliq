<?php

/**
 * The Themes page's table: every portal with its theme and its contrast.
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
 * @spec openspec/specs/portal-themes/spec.md#requirement-the-themes-overview-says-per-portal-whether-its-theme-is-readable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\Theme\PortalThemeOverview;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Answers the Themes page's portal rows.
 */
class ThemeOverviewController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request  The request.
	 * @param PortalThemeOverview $overview The rows.
	 */
	public function __construct(
		IRequest $request,
		private readonly PortalThemeOverview $overview,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every published portal with its theme and contrast verdict.
	 *
	 * @return JSONResponse `{portals: [...], poorCount}`.
	 *
	 * @auth admin-only A portal's house style is an administrator's concern.
	 *       Nextcloud expresses admin-only as the ABSENCE of an opt-out
	 *       attribute, so this tag is the declaration.
	 *
	 * @spec openspec/specs/portal-themes/spec.md#requirement-the-themes-overview-says-per-portal-whether-its-theme-is-readable
	 */
	public function portals(): JSONResponse {
		return new JSONResponse($this->overview->rows());
	}//end portals()
}//end class
