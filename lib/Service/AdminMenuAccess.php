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
use OCA\Portaliq\Service\Tenancy\PortalAdminScope;
use OCP\IGroupManager;
use OCP\IUser;
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
	 * The Nextcloud group of portaliq staff.
	 *
	 * The register's authorization names this group (and nothing broader),
	 * so a member reads and writes portaliq's records through OpenRegister.
	 * The repair step SeedStaffGroup creates it.
	 */
	public const STAFF_GROUP = 'portaliq-medewerkers';

	/**
	 * Constructor.
	 *
	 * @param IUserSession       $userSession The signed-in user.
	 * @param IGroupManager      $groups      Tells whether the user administers the instance.
	 * @param PageEditorService  $pageEditor  Who may edit portal pages (the editor groups).
	 * @param ActionAuthService  $actions     The action matrix (who may provision, answer access requests).
	 * @param PortalAdminScope|null $portalScope Which portals the user administers through `adminGroups`.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly PageEditorService $pageEditor,
		private readonly ActionAuthService $actions,
		private readonly ?PortalAdminScope $portalScope=null,
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
	 * - `portals`: may open the Portals page: an editor, or a member of a
	 *   portal's `adminGroups`.
	 * - `portalSlugs`: the portals that page lists, or null for all of them.
	 *   A member of an admin group who is no editor sees only their own.
	 *
	 * News is not gated here: every signed-in Nextcloud user may author news
	 * (staff-news-screen), so its menu entry shows for everyone.
	 *
	 * @return array{admin: bool, pages: bool, accounts: bool, accessRequests: bool, portals: bool, portalSlugs: array<int, string>|null}
	 *
	 * @spec openspec/changes/admin-menu-follows-roles/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
	 */
	public function forCurrentUser(): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return ['admin' => false, 'pages' => false, 'accounts' => false, 'accessRequests' => false, 'portals' => false, 'portalSlugs' => []];
		}

		$pages = $this->pageEditor->mayEdit(user: $user);
		$slugs = null;
		if ($pages === false) {
			$slugs = $this->administeredSlugs(user: $user);
		}

		return [
			'admin'          => $this->groups->isAdmin($user->getUID()),
			'pages'          => $pages,
			'accounts'       => $this->actions->can(user: $user, action: PortalAccountAdminController::ACTION_PROVISION),
			'accessRequests' => $this->actions->can(user: $user, action: AccessRequestAdminController::ACTION_ANSWER),
			'portals'        => $pages === true || $slugs === null || $slugs !== [],
			'portalSlugs'    => $slugs,
		];
	}//end forCurrentUser()

	/**
	 * Whether the signed-in user is portaliq staff and may open the app.
	 *
	 * Staff is an administrator, a member of the staff group, a member of a
	 * configured editor group, or a member of a group the action matrix grants
	 * any action. Everybody else signed in to Nextcloud (a self-registered
	 * account, an account in no group) is not staff: the app answers them
	 * with a refusal, and OpenRegister refuses them the register, which
	 * names only the staff group (close-portaliq-register-to-staff).
	 *
	 * @return bool True when the signed-in user is staff.
	 *
	 * @spec openspec/changes/close-portaliq-register-to-staff/specs/admin-ui/spec.md#requirement-only-staff-may-open-portaliqs-nextcloud-app-and-read-its-register
	 */
	public function isStaff(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$uid = $user->getUID();
		if ($this->groups->isAdmin($uid) === true || $this->groups->isInGroup($uid, self::STAFF_GROUP) === true) {
			return true;
		}

		if ($this->pageEditor->mayEdit(user: $user) === true) {
			return true;
		}

		if ($this->administeredSlugs(user: $user) !== []) {
			return true;
		}

		foreach (array_keys($this->actions->getMatrix()) as $action) {
			if ($this->actions->can(user: $user, action: (string)$action) === true) {
				return true;
			}
		}

		return false;
	}//end isStaff()

	/**
	 * The portals the user administers through `adminGroups`; null for an
	 * instance administrator, who administers all of them.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return array<int, string>|null The slugs, possibly empty.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
	 */
	private function administeredSlugs(IUser $user): ?array {
		if ($this->portalScope === null) {
			if ($this->groups->isAdmin($user->getUID()) === true) {
				return null;
			}

			return [];
		}

		return $this->portalScope->administeredSlugs(user: $user);
	}//end administeredSlugs()
}//end class
