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
 * - Is this Nextcloud user one of this portal's people? Only when they are a
 *   member of a group the portal names: its resident group, or its business
 *   group for people who act for a company. Membership is the whole test, so
 *   a staff account outside both groups never becomes a portal user by
 *   signing in. Each group carries its own audience.
 * - Which portal does such a user belong to, for sending them back to it?
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
	 * The audience of a business contact's account when the portal names none.
	 */
	public const DEFAULT_BUSINESS_AUDIENCE = 'supplier';

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
		return $this->namedGroup(portal: $portal, key: 'residentGroup');
	}//end residentGroup()

	/**
	 * The group whose members act for a company on this portal, or ''.
	 *
	 * Read from `authentication.registration.businessGroup`, under the same
	 * condition as the resident group: only a portal that offers the
	 * `nextcloud` mode has one. The registration app never fills it, so it
	 * opens no "Create an account" link.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function businessGroup(array $portal): string {
		return $this->namedGroup(portal: $portal, key: 'businessGroup');
	}//end businessGroup()

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
		return $this->namedAudience(portal: $portal, key: 'residentAudience', default: self::DEFAULT_AUDIENCE);
	}//end residentAudience()

	/**
	 * The audience a business contact's account gets: the portal's
	 * `authentication.registration.businessAudience`, or `supplier`.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function businessAudience(array $portal): string {
		return $this->namedAudience(portal: $portal, key: 'businessAudience', default: self::DEFAULT_BUSINESS_AUDIENCE);
	}//end businessAudience()

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
	public function portalGroups(array $portal): array {
		$groups = [];
		$resident = $this->residentGroup(portal: $portal);
		if ($resident !== '') {
			$groups[$resident] = $this->residentAudience(portal: $portal);
		}

		$business = $this->businessGroup(portal: $portal);
		if ($business !== '' && isset($groups[$business]) === false) {
			$groups[$business] = $this->businessAudience(portal: $portal);
		}

		return $groups;
	}//end portalGroups()

	/**
	 * The audience this Nextcloud user has on this portal, or '' when they
	 * are in none of the groups it names.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 * @param string               $uid    The Nextcloud user id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/business-audience-group/specs/portal-nextcloud-account-login/spec.md#requirement-a-portal-may-name-a-business-group-with-its-own-audience-req-ncl-008
	 */
	public function audienceOf(array $portal, string $uid): string {
		if ($uid === '') {
			return '';
		}

		foreach ($this->portalGroups(portal: $portal) as $gid => $audience) {
			if ($this->groups->isInGroup($uid, (string)$gid) === true) {
				return $audience;
			}
		}

		return '';
	}//end audienceOf()

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
	public function admitResident(array $portal, IUser $user): bool {
		if ($this->provisioner === null) {
			return false;
		}

		$audience = $this->audienceOf(portal: $portal, uid: $user->getUID());
		if ($audience === '') {
			return false;
		}

		$provisioned = $this->provisioner->provision(
			appId: Application::APP_ID,
			uid: $user->getUID(),
			portal: (string)($portal['slug'] ?? ''),
			audience: $audience,
			organisation: trim((string)($portal['organisation'] ?? '')),
			email: (string)($user->getEMailAddress() ?? ''),
			displayName: $user->getDisplayName()
		);

		return is_array($provisioned);
	}//end admitResident()

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
	public function residentPortalOf(string $uid): ?array {
		if ($uid === '' || $this->groups->isAdmin($uid) === true) {
			return null;
		}

		foreach ($this->portals->allPublishedPortals() as $portal) {
			if (is_array($portal) === true && (string)($portal['slug'] ?? '') !== '' && $this->audienceOf(portal: $portal, uid: $uid) !== '') {
				return $portal;
			}
		}

		return null;
	}//end residentPortalOf()

	/**
	 * The group a portal names under `authentication.registration.<key>`, or
	 * '' unless the portal offers the `nextcloud` mode.
	 *
	 * @param array<string, mixed> $portal The portal's own configuration row.
	 * @param string               $key    `residentGroup` or `businessGroup`.
	 *
	 * @return string
	 */
	private function namedGroup(array $portal, string $key): string {
		$authentication = (array)($portal['authentication'] ?? []);
		$modes = ($authentication['modes'] ?? []);
		if (is_array($modes) === false || in_array(self::MODE, $modes, true) === false) {
			return '';
		}

		$registration = ($authentication['registration'] ?? []);
		if (is_array($registration) === false) {
			return '';
		}

		$group = ($registration[$key] ?? '');
		if (is_string($group) === false) {
			return '';
		}

		return trim($group);
	}//end namedGroup()

	/**
	 * The audience a portal names under `authentication.registration.<key>`,
	 * or the default when it names none.
	 *
	 * @param array<string, mixed> $portal  The portal's own configuration row.
	 * @param string               $key     `residentAudience` or `businessAudience`.
	 * @param string               $default The audience when none is named.
	 *
	 * @return string
	 */
	private function namedAudience(array $portal, string $key, string $default): string {
		$registration = (((array)($portal['authentication'] ?? []))['registration'] ?? []);
		$audience = '';
		if (is_array($registration) === true && is_string($registration[$key] ?? null) === true) {
			$audience = trim($registration[$key]);
		}

		if ($audience === '') {
			return $default;
		}

		return $audience;
	}//end namedAudience()

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
