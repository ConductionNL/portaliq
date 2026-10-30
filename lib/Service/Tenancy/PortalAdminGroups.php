<?php

/**
 * Portaliq Portal Admin Groups
 *
 * Reads and writes the Nextcloud groups that administer one portal
 * (operate-portals-per-organisation REQ-OPO-005).
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\IGroupManager;

/**
 * The portal's `adminGroups`, each with its display name, and the groups an
 * instance administrator can pick from.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
 */
class PortalAdminGroups {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager      $groups Resolves group ids and names.
	 * @param PortalObjectWriter $writer Writes the portal.
	 */
	public function __construct(
		private readonly IGroupManager $groups,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * The portal's administering groups as `{id, label}` rows. A group that no
	 * longer exists keeps its id as its label, so it can still be removed.
	 *
	 * @param array<string, mixed> $portal The portal object.
	 *
	 * @return array<int, array{id: string, label: string}>
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
	 */
	public function listFor(array $portal): array {
		$rows = [];
		foreach (PortalAdminScope::groupsOf(portal: $portal) as $gid) {
			$group  = $this->groups->get($gid);
			$rows[] = [
				'id'    => $gid,
				'label' => ($group?->getDisplayName() ?? $gid),
			];
		}

		return $rows;
	}//end listFor()

	/**
	 * Every group on the instance, for the picker.
	 *
	 * @return array<int, array{id: string, label: string}> The groups, id-sorted.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
	 */
	public function available(): array {
		$rows = [];
		foreach ($this->groups->search('') as $group) {
			$rows[] = [
				'id'    => $group->getGID(),
				'label' => $group->getDisplayName(),
			];
		}

		usort($rows, static fn (array $a, array $b): int => strcasecmp($a['id'], $b['id']));

		return $rows;
	}//end available()

	/**
	 * Store the portal's administering groups. Ids of groups that do not exist
	 * are dropped, so a typo cannot hand a portal to a group made later.
	 *
	 * @param array<string, mixed> $portal The portal object.
	 * @param array<mixed>         $groups Group ids, or `{id}` rows from the picker.
	 *
	 * @return array<string, mixed>|null The stored portal, or null when the write failed.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t12
	 */
	public function save(array $portal, array $groups): ?array {
		$ids = [];
		foreach ($groups as $entry) {
			$gid = $entry;
			if (is_array($entry) === true) {
				$gid = ($entry['id'] ?? '');
			}

			if (is_string($gid) === false || trim($gid) === '') {
				continue;
			}

			$gid = trim($gid);
			if ($this->groups->groupExists($gid) === true && in_array($gid, $ids, true) === false) {
				$ids[] = $gid;
			}
		}

		$id = (string)($portal['id'] ?? $portal['uuid'] ?? ($portal['@self']['id'] ?? ''));

		return $this->writer->updateObject(
			register: 'portaliq',
			schema: 'portal',
			scopeField: 'slug',
			subjectRef: (string)($portal['slug'] ?? ''),
			organisation: '',
			id: $id,
			data: [PortalAdminScope::FIELD => $ids]
		);
	}//end save()
}//end class
