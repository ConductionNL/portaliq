<?php

/**
 * Portaliq Portal Admin Scope
 *
 * Who may administer one portal on a shared installation: an instance
 * administrator everywhere, and a member of one of the portal's own
 * `adminGroups` on that portal only.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Service\PortalSlugLookup;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * The one answer every per-portal administration route asks.
 *
 * A portal the caller may not administer is answered as if it did not exist
 * (404), so a member of one organisation learns nothing about another
 * organisation's portal. Only an instance administrator may change who
 * administers a portal (`adminGroups`) or provision one; this class never
 * grants that.
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */
class PortalAdminScope {

	/**
	 * Constructor.
	 *
	 * @param IUserSession                    $userSession  The signed-in user.
	 * @param IGroupManager                   $groupManager Instance admin and group membership.
	 * @param ScopedCollectionReaderInterface $reader       Reads the portal by its slug.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ScopedCollectionReaderInterface $reader,
	) {
	}//end __construct()

	/**
	 * Whether the user is an instance administrator.
	 *
	 * @param IUser|null $user The user; the signed-in one when null.
	 *
	 * @return bool True for an instance administrator, false without a user.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function isInstanceAdmin(?IUser $user = null): bool {
		$subject = ($user ?? $this->userSession->getUser());
		if ($subject === null) {
			return false;
		}

		return $this->groupManager->isAdmin($subject->getUID());
	}//end isInstanceAdmin()

	/**
	 * Whether the user may administer this portal: an instance administrator,
	 * or a member of one of the groups the portal names in `adminGroups`.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 * @param IUser|null           $user   The user; the signed-in one when null.
	 *
	 * @return bool True when they may; false without a user.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function mayAdminister(array $portal, ?IUser $user = null): bool {
		$subject = ($user ?? $this->userSession->getUser());
		if ($subject === null) {
			return false;
		}

		if ($this->groupManager->isAdmin($subject->getUID()) === true) {
			return true;
		}

		foreach (self::adminGroupsOf(portal: $portal) as $gid) {
			if ($this->groupManager->isInGroup($subject->getUID(), $gid) === true) {
				return true;
			}
		}

		return false;
	}//end mayAdminister()

	/**
	 * Whether the signed-in user may administer the portal with this slug. An
	 * unknown portal, and one that cannot be read, answer false.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return bool True when they may.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public function mayAdministerSlug(string $slug): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		// An instance administrator needs no read of the portal.
		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		try {
			$portal = (new PortalSlugLookup(reader: $this->reader))->find(slug: $slug);
		} catch (Throwable $e) {
			return false;
		}

		return $portal !== null && $this->mayAdminister(portal: $portal, user: $user);
	}//end mayAdministerSlug()

	/**
	 * The group ids a portal names in `adminGroups`: non-empty strings only.
	 *
	 * @param array<string, mixed> $portal The portal row.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t11
	 */
	public static function adminGroupsOf(array $portal): array {
		$groups = ($portal['adminGroups'] ?? null);
		if (is_array($groups) === false) {
			return [];
		}

		$out = [];
		foreach ($groups as $gid) {
			if (is_string($gid) === true && trim($gid) !== '') {
				$out[] = trim($gid);
			}
		}

		return array_values(array_unique($out));
	}//end adminGroupsOf()
}//end class
