<?php

/**
 * Contract of NextcloudRegistrationDoor
 *
 * The public surface of NextcloudRegistrationDoor that other components call, so they depend
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

use OCP\IUser;

/**
 * Contract of NextcloudRegistrationDoor.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NextcloudRegistrationDoorInterface {

	/**
	 * The Nextcloud app that lets a visitor make their own account.
	 */
	public const REGISTRATION_APP = 'registration';

	/**
	 * The registration app's setting that names the group new users join.
	 */
	public const REGISTRATION_GROUP_KEY = 'registered_user_group';

	/**
	 * The groups whose members are this portal's people, each with the
	 * audience its members' accounts get: group id to audience.
	 *
	 * The resident group comes first. A business group that names the same
	 * group as the resident group adds nothing: its members are residents.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function portalGroups(array $portal): array;

	/**
	 * The published portal whose resident or business group this user is in, or null.
	 *
	 * An administrator is never sent anywhere: they keep the app they opened.
	 * When the user belongs to more than one portal's group, the first
	 * published portal wins; that is a configuration for an administrator to
	 * untangle, not a reason to refuse.
	 *
	 * @param string $uid The Nextcloud user id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-a-resident-who-opens-the-workplace-is-sent-back-to-their-portal-req-ncl-006
	 */
	public function residentPortalOf(string $uid): ?array;

	/**
	 * Where "Create an account" leads, or '' when it leads nowhere.
	 *
	 * The registration app's own form, only when the portal names a resident
	 * group (so it offers the `nextcloud` mode), the registration app is
	 * enabled, and that app puts new users in exactly that group. A
	 * registration app that puts them in another group, or in none, would
	 * make accounts this portal never admits, so the link is not drawn.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return string The absolute address, or ''.
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-registration-req-ncl-005
	 */
	public function registerUrl(array $portal): string;

	/**
	 * Write the first portal account of a resident or business contact;
	 * whether one now exists.
	 *
	 * False for anybody outside the groups the portal names. The account is
	 * written by the same provisioner an app uses
	 * (an-app-provisions-a-nextcloud-account): active, its subjectRef the
	 * user id, in the portal's organisation, with the audience of the user's
	 * group, the address unverified. Its refusals (an account in the organisation that
	 * holds the same address, a portal without an organisation) stand.
	 *
	 * @param array<string, mixed> $portal The portal, which offers `nextcloud`.
	 * @param IUser                $user   The signed-in Nextcloud user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-only-an-existing-active-portal-account-gets-a-session-req-ncl-003
	 */
	public function admitResident(array $portal, IUser $user): bool;
}//end interface
