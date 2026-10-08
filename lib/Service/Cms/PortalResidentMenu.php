<?php

/**
 * The public projection of a portal's resident menu: the card label and the
 * portal's own groups (zuiddrecht-resident-pages-match-the-boards).
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Projects `residentMenu` on named keys: plain text, item names only, and at
 * most 12 groups of 20 items.
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 */
class PortalResidentMenu {

	/**
	 * The resident menu's card label and groups, each only when the portal
	 * names them.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `{cardLabel?, groups?}`.
	 *
	 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-whom-the-resident-acts-for
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 */
	public function project(array $portal): array {
		$menu = $portal['residentMenu'] ?? [];
		if (is_array($menu) === false) {
			return [];
		}

		$out   = [];
		$label = $this->text(value: ($menu['cardLabel'] ?? ''));
		if ($label !== '') {
			$out['cardLabel'] = $label;
		}

		$groups = $this->groups(groups: ($menu['groups'] ?? []));
		if ($groups !== []) {
			$out['groups'] = $groups;
		}

		return $out;
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
