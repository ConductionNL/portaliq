<?php

/**
 * Portaliq Nextcloud Registration Door (nextcloud-account-registration)
 *
 * A portal that signs residents in with a Nextcloud account (mode
 * `nextcloud`) may let a visitor make that account themselves, through
 * Nextcloud's own registration app. Portaliq builds no account form of its
 * own for this: the registration app sends the activation mail, asks for the
 * password and puts the new user in one group. This class answers the three
 * questions portaliq has about that arrangement:
 *
 * - Where does "Create an account" lead? To the registration app's own form,
 *   but only when the portal offers the `nextcloud` mode, names a resident
 *   group, and the registration app is enabled and puts new users in exactly
 *   that group. Otherwise nowhere, and the link is not drawn: a door that
 *   leads nowhere is not shown (REQ-IWI-005).
 * - Is this Nextcloud user a resident of this portal? Only when they are a
 *   member of the group the portal names. Membership is the whole test, so a
 *   staff account outside the group never becomes a resident by signing in.
 * - Which portal does a resident belong to, for sending them back to it?
 *
 * Every address it hands out is built here from a route name. None is ever
 * taken from the request.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-registration-req-ncl-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalResolver;
use OCP\App\IAppManager;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCA\Portaliq\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;

/**
 * Decides the registration link and resident membership of a `nextcloud`-mode portal.
 *
 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-registration-req-ncl-005
 */
class NextcloudRegistrationDoor {
	/**
	 * The Nextcloud app that lets a visitor make their own account.
	 */
	public const REGISTRATION_APP = 'registration';

	/**
	 * The registration app's setting that names the group new users join.
	 */
	public const REGISTRATION_GROUP_KEY = 'registered_user_group';

	/**
	 * The registration app's email form.
	 */
	public const REGISTRATION_ROUTE = 'registration.register.showEmailForm';

	/**
	 * The audience of a resident account when the portal names none.
	 */
	public const DEFAULT_AUDIENCE = 'citizen';

	/**
	 * The sign-in mode this door belongs to.
	 */
	private const MODE = 'nextcloud';

	/**
	 * Constructor.
	 *
	 * @param IAppManager    $apps      Whether the registration app is enabled.
	 * @param IAppConfig     $appConfig The registration app's group setting.
	 * @param IURLGenerator  $urls      Builds the addresses.
	 * @param IGroupManager  $groups    Group membership.
	 * @param PortalResolver $portals   The published portals.
	 * @param NextcloudAccountProvisioner|null $provisioner Writes a resident's first account.
	 */
	public function __construct(
		private readonly IAppManager $apps,
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urls,
		private readonly IGroupManager $groups,
		private readonly PortalResolver $portals,
		private readonly ?NextcloudAccountProvisioner $provisioner = null,
	) {
	}//end __construct()

	/**
	 * The group whose members are this portal's residents, or ''.
	 *
	 * '' unless the portal offers the `nextcloud` mode and names a group in
	 * `authentication.registration.residentGroup`. A portal that signs in
	 * only by e-mail link, DigiD or OIDC has no residents here, whatever it
	 * names (REQ-IWI-014: the e-mail link never opens registration).
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-registration-req-ncl-005
	 */
	public function residentGroup(array $portal): string {
		$authentication = (array)($portal['authentication'] ?? []);
		$modes = ($authentication['modes'] ?? []);
		if (is_array($modes) === false || in_array(self::MODE, $modes, true) === false) {
			return '';
		}

		$registration = ($authentication['registration'] ?? []);
		if (is_array($registration) === false) {
			return '';
		}

		$group = ($registration['residentGroup'] ?? '');
		if (is_string($group) === false) {
			return '';
		}

		return trim($group);
	}//end residentGroup()

	/**
	 * The audience a resident's account gets: the portal's
	 * `authentication.registration.residentAudience`, or `citizen`.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-only-an-existing-active-portal-account-gets-a-session-req-ncl-003
	 */
	public function residentAudience(array $portal): string {
		$registration = (((array)($portal['authentication'] ?? []))['registration'] ?? []);
		$audience = '';
		if (is_array($registration) === true && is_string($registration['residentAudience'] ?? null) === true) {
			$audience = trim($registration['residentAudience']);
		}

		if ($audience === '') {
			return self::DEFAULT_AUDIENCE;
		}

		return $audience;
	}//end residentAudience()

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
	public function registerUrl(array $portal): string {
		$group = $this->residentGroup(portal: $portal);
		if ($group === '') {
			return '';
		}

		if ($this->apps->isEnabledForAnyone(self::REGISTRATION_APP) === false) {
			return '';
		}

		if ($this->registrationGroup() !== $group) {
			return '';
		}

		return $this->urls->linkToRouteAbsolute(self::REGISTRATION_ROUTE);
	}//end registerUrl()

	/**
	 * Whether a Nextcloud user is a resident of this portal: a member of the
	 * group it names.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 * @param string               $uid    The Nextcloud user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-only-an-existing-active-portal-account-gets-a-session-req-ncl-003
	 */
	public function isResident(array $portal, string $uid): bool {
		$group = $this->residentGroup(portal: $portal);
		if ($group === '' || $uid === '') {
			return false;
		}

		return $this->groups->isInGroup($uid, $group);
	}//end isResident()

	/**
	 * Write the first portal account of a resident; whether one now exists.
	 *
	 * False for anybody outside the portal's resident group. The account is
	 * written by the same provisioner an app uses
	 * (an-app-provisions-a-nextcloud-account): active, its subjectRef the
	 * user id, in the portal's organisation, with the resident audience, the
	 * address unverified. Its refusals (an account in the organisation that
	 * holds the same address, a portal without an organisation) stand.
	 *
	 * @param array<string, mixed> $portal The portal, which offers `nextcloud`.
	 * @param IUser                $user   The signed-in Nextcloud user.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-only-an-existing-active-portal-account-gets-a-session-req-ncl-003
	 */
	public function admitResident(array $portal, IUser $user): bool {
		if ($this->provisioner === null || $this->isResident(portal: $portal, uid: $user->getUID()) === false) {
			return false;
		}

		$provisioned = $this->provisioner->provision(
			appId: Application::APP_ID,
			uid: $user->getUID(),
			portal: (string)($portal['slug'] ?? ''),
			audience: $this->residentAudience(portal: $portal),
			organisation: trim((string)($portal['organisation'] ?? '')),
			email: (string)($user->getEMailAddress() ?? ''),
			displayName: $user->getDisplayName()
		);

		return is_array($provisioned);
	}//end admitResident()

	/**
	 * The published portal this user is a resident of, or null.
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
	public function residentPortalOf(string $uid): ?array {
		if ($uid === '' || $this->groups->isAdmin($uid) === true) {
			return null;
		}

		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true && (string)($portal['slug'] ?? '') !== '' && $this->isResident(portal: $portal, uid: $uid) === true) {
				return $portal;
			}
		}

		return null;
	}//end residentPortalOf()

	/**
	 * The group the registration app puts new users in, or ''.
	 *
	 * The registration app stores `none` for no group.
	 *
	 * @return string
	 */
	private function registrationGroup(): string {
		try {
			$group = $this->appConfig->getValueString(self::REGISTRATION_APP, self::REGISTRATION_GROUP_KEY, '');
		} catch (AppConfigTypeConflictException) {
			// Stored under another type than a string: not a group we can read,
			// so the door stays shut.
			return '';
		}

		$group = trim($group);
		if ($group === 'none') {
			return '';
		}

		return $group;
	}//end registrationGroup()
}//end class
