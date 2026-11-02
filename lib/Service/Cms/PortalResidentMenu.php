<?php

/**
 * The public projection of a portal's resident menu: the card label, the
 * portal's own groups (zuiddrecht-resident-pages-match-the-boards) and the
 * items it leaves out (resident-menu-leave-out).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Projects `residentMenu` on named keys: plain text, item names only, at
 * most 12 groups of 20 items, and at most 20 left-out items.
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 */
class PortalResidentMenu {

	/**
	 * The resident menu's card label, groups and left-out items, each only
	 * when the portal names them.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `{cardLabel?, groups?, leaveOut?}`.
	 *
	 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-whom-the-resident-acts-for
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
	 */
	public function project(array $portal): array {
		$menu = $portal['residentMenu'] ?? [];
		if (is_array($menu) === false) {
			return [];
		}

		// An empty part is left out.
		return array_filter(
			[
				'cardLabel' => $this->text(value: ($menu['cardLabel'] ?? '')),
				'groups'    => $this->groups(groups: ($menu['groups'] ?? [])),
				'leaveOut'  => $this->leaveOut(declared: ($menu['leaveOut'] ?? [])),
			],
			static fn ($part): bool => $part !== '' && $part !== []
		);
	}//end project()

	/**
	 * The portal's own groups: each a title and its items by name, at most
	 * 12 groups of 20. A group without a title or without an item is left out.
	 *
	 * @param mixed $groups The authored groups.
	 *
	 * @return array<int, array{title: string, items: array<int, string>}>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 */
	private function groups(mixed $groups): array {
		$out = [];
		foreach (array_slice((array)$groups, 0, 12) as $group) {
			if (is_array($group) === false) {
				continue;
			}

			$items = $this->items(items: ($group['items'] ?? []));
			$title = $this->text(value: ($group['title'] ?? ''));
			if ($items !== [] && $title !== '') {
				$out[] = ['title' => $title, 'items' => $items];
			}
		}

		return $out;
	}//end groups()

	/**
	 * The item names of one group, at most 20; anything that is not a name
	 * is left out.
	 *
	 * @param mixed $items The authored items.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 */
	private function items(mixed $items): array {
		$out = [];
		foreach (array_slice((array)$items, 0, 20) as $item) {
			if (is_string($item) === true && preg_match('/^[a-z0-9][a-z0-9:_-]{0,79}$/i', $item) === 1) {
				$out[] = $item;
			}
		}

		return $out;
	}//end items()

	/**
	 * The items a portal leaves out of the menu: well-formed names, at most
	 * 20, each once, never `overview` (resident-menu-leave-out).
	 *
	 * @param mixed $declared The declared list.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
	 */
	private function leaveOut(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$names = [];
		foreach (array_slice($declared, 0, 20) as $item) {
			if (is_string($item) === true && $item !== 'overview' && preg_match('/^[a-z0-9][a-z0-9:_-]{0,79}$/i', $item) === 1) {
				$names[] = $item;
			}
		}

		return array_values(array_unique($names));
	}//end leaveOut()

	/**
	 * A scalar as trimmed text; anything else as empty.
	 *
	 * @param mixed $value The authored value.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
