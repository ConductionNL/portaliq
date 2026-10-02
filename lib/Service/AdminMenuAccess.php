<?php

/**
 * Portaliq admin menu access
 *
 * Which parts of portaliq's Nextcloud app the signed-in user may use, so the
 * app shows a teacher the News page and not the portal administration. The
 * menu only hides what the server already refuses: every write keeps its own
 * check (the editor groups, the action matrix, admin-only settings).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Controller\AccessRequestAdminController;
use OCA\Portaliq\Controller\PortalAccountAdminController;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The four access flags the app menu reads.
 *
 * Fail-closed: without a signed-in user every flag is false.
 *
 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
 */
class AdminMenuAccess {

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession The signed-in user.
	 * @param IGroupManager      $groups      Tells whether the user administers the instance.
	 * @param PageEditorService  $pageEditor  Who may edit portal pages (the editor groups).
	 * @param ActionAuthService  $actions     The action matrix (who may provision, answer access requests).
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly PageEditorService $pageEditor,
		private readonly ActionAuthService $actions,
	) {
	}//end __construct()

	/**
	 * The signed-in user's access flags.
	 *
	 * - `admin`: a Nextcloud administrator (portals' setup, themes, request
	 *   forms, submissions, reports, flows, the store).
	 * - `pages`: may edit portal pages (administrator or an editor group):
	 *   portals, media and notices.
	 * - `accounts`: may provision portal accounts (`portal.provision`):
	 *   accounts and invitations.
	 * - `accessRequests`: may answer access requests
	 *   (`portal.answer-access-request`).
	 *
	 * News is not gated here: every signed-in Nextcloud user may author news
	 * (staff-news-screen), so its menu entry shows for everyone.
	 *
	 * @return array{admin: bool, pages: bool, accounts: bool, accessRequests: bool}
	 *
	 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
	 */
	public function forCurrentUser(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['admin' => false, 'pages' => false, 'accounts' => false, 'accessRequests' => false];
		}

		return [
			'admin'          => $this->groups->isAdmin($user->getUID()),
			'pages'          => $this->pageEditor->mayEdit(user: $user),
			'accounts'       => $this->actions->can(user: $user, action: PortalAccountAdminController::ACTION_PROVISION),
			'accessRequests' => $this->actions->can(user: $user, action: AccessRequestAdminController::ACTION_ANSWER),
		];
	}//end forCurrentUser()
}//end class
