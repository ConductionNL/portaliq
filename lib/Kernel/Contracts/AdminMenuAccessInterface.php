<?php

/**
 * Contract of AdminMenuAccess
 *
 * The public surface of AdminMenuAccess that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of AdminMenuAccess.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdminMenuAccessInterface {

	/**
	 * The Nextcloud group of portaliq staff.
	 *
	 * The register's authorization names this group (and nothing broader),
	 * so a member reads and writes portaliq's records through OpenRegister.
	 * The repair step SeedStaffGroup creates it.
	 */
	public const STAFF_GROUP = 'portaliq-medewerkers';

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
	 * @spec openspec/specs/admin-ui/spec.md#requirement-the-app-menu-must-show-a-user-only-the-pages-their-role-may-use
	 */
	public function forCurrentUser(): array;

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
	public function isStaff(): bool;
}//end interface
