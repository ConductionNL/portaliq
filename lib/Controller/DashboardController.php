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
use OCA\Portaliq\Service\Identity\NextcloudRegistrationDoor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Controller for the main Portaliq dashboard page.
 *
 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
 */
class DashboardController extends Controller {
	/**
	 * Constructor for the DashboardController.
	 *
	 * @param IRequest             $request      The request object
	 * @param AdminMenuAccess|null $access       Which pages the signed-in user may use.
	 * @param IInitialState|null   $initialState Hands those flags to the app.
	 * @param NextcloudRegistrationDoor|null $residents Which portal a resident belongs to (REQ-NCL-006).
	 * @param IUserSession|null    $userSession  The signed-in user.
	 * @param IURLGenerator|null   $urls         Builds the portal's account route.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly ?AdminMenuAccess $access=null,
		private readonly ?IInitialState $initialState=null,
		private readonly ?NextcloudRegistrationDoor $residents=null,
		private readonly ?IUserSession $userSession=null,
		private readonly ?IURLGenerator $urls=null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Render the main dashboard page.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @return Response The app, or a resident's way back to their portal.
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-001
	 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-a-resident-who-opens-the-workplace-is-sent-back-to-their-portal-req-ncl-006
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
	 */
	public function page(): Response {
		// A resident is not a workplace user. Nextcloud's registration app
		// ends on the instance's default page, which the tenant points here;
		// a member of a portal's resident group goes on to that portal's
		// account route, which signs them in to the portal
		// (nextcloud-account-registration REQ-NCL-006).
		$back = $this->residentWayBack();
		if ($back !== null) {
			return $back;
		}

		// The app is staff's workplace. A signed-in account that is neither a
		// resident nor staff (a self-registered account outside the resident
		// group, an account in no group) is refused rather than shown the
		// staff dashboard. Without the access service nobody can be shown to
		// be staff, so the page fails closed (close-portaliq-register-to-staff).
		if ($this->access === null || $this->access->isStaff() === false) {
			return $this->refusal();
		}

		// The app menu shows only the pages this user's role may use
		// (admin-menu-follows-roles). Without the flags the app shows the
		// pages every signed-in user may use, never more.
		if ($this->initialState !== null) {
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
	 * @return Response
	 *
	 * @spec openspec/specs/dashboard-page/spec.md#REQ-DASH-002
	 */
	public function catchAll(): Response {
		return $this->page();
	}//end catchAll()

	/**
	 * The page a signed-in account that is not staff gets: Nextcloud's 403.
	 *
	 * @return TemplateResponse The refusal, with status 403.
	 *
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
	 */
	private function refusal(): TemplateResponse {
		$response = new TemplateResponse('core', '403', [], TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus(Http::STATUS_FORBIDDEN);

		return $response;
	}//end refusal()

	/**
	 * The redirect to a resident's portal, or null for anybody else.
	 *
	 * The address is the portal's account route, built from its route name
	 * and the portal's own slug; nothing in it comes from the request.
	 *
	 * @return RedirectResponse|null
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-a-resident-who-opens-the-workplace-is-sent-back-to-their-portal-req-ncl-006
	 */
	private function residentWayBack(): ?RedirectResponse {
		if ($this->residents === null || $this->userSession === null || $this->urls === null) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		$portal = $this->residents->residentPortalOf(uid: $user->getUID());
		if ($portal === null) {
			return null;
		}

		return new RedirectResponse(
			$this->urls->linkToRoute('portaliq.session.nextcloud', ['portal' => (string)$portal['slug']]),
			Http::STATUS_FOUND
		);
	}//end residentWayBack()
}//end class
