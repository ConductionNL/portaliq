<?php

/**
 * Portaliq Dashboard Controller
 *
 * Controller for the main Portaliq dashboard page.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\AdminMenuAccess;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;

/**
 * Controller for the main Portaliq dashboard page.
 */
class DashboardController extends Controller {
	/**
	 * Constructor for the DashboardController.
	 *
	 * @param IRequest             $request      The request object
	 * @param AdminMenuAccess|null $access       Which pages the signed-in user may use.
	 * @param IInitialState|null   $initialState Hands those flags to the app.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ?AdminMenuAccess $access=null,
		private readonly ?IInitialState $initialState=null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the main dashboard page.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
	 */
	public function page(): TemplateResponse {
		// The app menu shows only the pages this user's role may use
		// (admin-menu-follows-roles). Without the flags the app shows the
		// pages every signed-in user may use, never more.
		if ($this->access !== null && $this->initialState !== null) {
			$this->initialState->provideInitialState('access', $this->access->forCurrentUser());
		}

		return new TemplateResponse(Application::APP_ID, 'index');
	}//end page()

	/**
	 * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return TemplateResponse
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-002
	 */
	public function catchAll(): TemplateResponse {
		return $this->page();
	}//end catchAll()
}//end class
