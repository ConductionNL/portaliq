<?php

/**
 * Portaliq Portal Admin Scope
 *
 * Who may administer which portal on a shared installation: an instance
 * administrator administers every portal, a member of one of a portal's
 * `adminGroups` administers that portal only.
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
 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Answers per portal whether the signed-in user may administer it.
 *
 * Fail-closed: no session administers nothing, and a portal without
 * `adminGroups` is administered by instance administrators only.
 *
 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
 */
class PortalAdminScope {

	/**
	 * The portal field that names the administering groups.
	 */
	public const FIELD = 'adminGroups';

	/**
	 * Constructor.
	 *
	 * @param IUserSession     $userSession The signed-in user.
	 * @param IGroupManager    $groups      Membership and instance admin rights.
	 * @param ExampleSiteStore $store       Reads the portals.
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groups,
		private readonly ExampleSiteStore $store,
	) {
	}//end __construct()

	/**
	 * Whether the user may administer the portal.
	 *
	 * @param array<string, mixed> $portal The portal object.
	 * @param IUser|null           $user   The user, or null for the signed-in user.
	 *
	 * @return bool True for an instance administrator or a member of one of its `adminGroups`.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
	 */
	public function mayAdminister(array $portal, ?IUser $user=null): bool {
		$subject = ($user ?? $this->userSession->getUser());
		if ($subject === null) {
			return false;
		}

		$uid = $subject->getUID();
		if ($this->groups->isAdmin($uid) === true) {
			return true;
		}

		foreach ($this->groupsOf(portal: $portal) as $gid) {
			if ($this->groups->isInGroup($uid, $gid) === true) {
				return true;
			}
		}

		return false;
	}//end mayAdminister()

	/**
	 * Whether the user may administer the portal with this slug. An unknown
	 * slug answers false, so a caller cannot tell it from a portal of another
	 * organisation.
	 *
	 * @param string     $slug The portal slug.
	 * @param IUser|null $user The user, or null for the signed-in user.
	 *
	 * @return bool True when the portal exists and the user may administer it.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
	 */
	public function mayAdministerSlug(string $slug, ?IUser $user=null): bool {
		$portal = $this->portalBySlug(slug: $slug);
		if ($portal === null) {
			return false;
		}

		return $this->mayAdminister(portal: $portal, user: $user);
	}//end mayAdministerSlug()

	/**
	 * One portal by slug, in any status, or null.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null The portal object.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
	 */
	public function portalBySlug(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		foreach ($this->store->find(schema: 'portal', filters: []) as $portal) {
			if (($portal['slug'] ?? null) === $slug) {
				return $portal;
			}
		}

		return null;
	}//end portalBySlug()

	/**
	 * The slugs of the portals the user administers through `adminGroups`,
	 * or null for an instance administrator, who administers every portal.
	 *
	 * @param IUser|null $user The user, or null for the signed-in user.
	 *
	 * @return array<int, string>|null The slugs, possibly empty; null means all.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
	 */
	public function administeredSlugs(?IUser $user=null): ?array {
		$subject = ($user ?? $this->userSession->getUser());
		if ($subject === null) {
			return [];
		}

		if ($this->groups->isAdmin($subject->getUID()) === true) {
			return null;
		}

		$slugs = [];
		foreach ($this->store->find(schema: 'portal', filters: []) as $portal) {
			$slug = ($portal['slug'] ?? null);
			if (is_string($slug) === true && $slug !== '' && $this->mayAdminister(portal: $portal, user: $subject) === true) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end administeredSlugs()

	/**
	 * Whether the user may change which groups administer a portal. Only an
	 * instance administrator hands out those groups.
	 *
	 * @param IUser|null $user The user, or null for the signed-in user.
	 *
	 * @return bool True for an instance administrator.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
	 */
	public function mayChangeAdminGroups(?IUser $user=null): bool {
		$subject = ($user ?? $this->userSession->getUser());
		if ($subject === null) {
			return false;
		}

		return $this->groups->isAdmin($subject->getUID());
	}//end mayChangeAdminGroups()

	/**
	 * The portal's administering group ids: unique, non-empty strings.
	 *
	 * @param array<string, mixed> $portal The portal object.
	 *
	 * @return array<int, string> The group ids.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/operate-portals-per-organisation/spec.md#requirement-each-organisation-looks-after-its-own-portal-on-a-shared-installation-req-opo-005
	 */
	public function groupsOf(array $portal): array {
		$ids = [];
		$raw = ($portal[self::FIELD] ?? []);
		if (is_array($raw) === false) {
			return [];
		}

		foreach ($raw as $gid) {
			if (is_string($gid) === true && trim($gid) !== '' && in_array(trim($gid), $ids, true) === false) {
				$ids[] = trim($gid);
			}
		}

		return $ids;
	}//end groupsOf()
}//end class
