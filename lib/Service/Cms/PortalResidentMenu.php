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
 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-declared-menu-item-may-carry-the-boards-word
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Projects `residentMenu` on named keys: plain text, item names only, at
 * most 12 groups of 20 items, and at most 20 left-out items; the person
 * block's collection and fields, and at most 20 second addresses.
 *
 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
 * @spec openspec/changes/resident-menu-leave-out/specs/site-resident-menu/spec.md#requirement-a-portal-may-leave-items-out-of-the-resident-menu
 */
class PortalResidentMenu {

	/**
	 * The shape of an item name: a section, or a contributed page as `app:page`.
	 */
	private const NAME = '/^[a-z0-9][a-z0-9:_-]{0,79}$/i';

	/**
	 * The shape of a second address under `/mijn/`.
	 */
	private const ADDRESS = '/^[a-z0-9][a-z0-9-]{0,39}$/';

	/**
	 * The longest label a declared item may carry.
	 */
	private const MAX_LABEL = 60;

	/**
	 * The resident menu's card label, groups and left-out items, each only
	 * when the portal names them.
	 *
	 * @param array<string, mixed> $portal The portal record.
	 *
	 * @return array<string, mixed> `{cardLabel?, groups?, leaveOut?, person?, routes?, phoneHeader?}`.
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
				'person'    => $this->person(declared: ($menu['person'] ?? null)),
				'routes'    => $this->routes(declared: ($menu['routes'] ?? null)),
				// The phone header of the own area (mijn-phone-chrome).
				'phoneHeader' => $this->phoneHeader(declared: ($menu['phoneHeader'] ?? null)),
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
	 * The items of one group, at most 20: each a name, or `{item, label}`
	 * when the portal gives the item the board's word (a label of at most 60
	 * characters). Anything that is not a name is left out.
	 *
	 * @param mixed $items The authored items.
	 *
	 * @return array<int, string|array{item: string, label: string}>
	 *
	 * @spec openspec/changes/zuiddrecht-resident-pages-match-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-lay-out-the-resident-menu-and-its-cases-page
	 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-declared-menu-item-may-carry-the-boards-word
	 */
	private function items(mixed $items): array {
		$out = [];
		foreach (array_slice((array)$items, 0, 20) as $item) {
			$labelled = $this->labelledItem(item: $item);
			if ($labelled !== null) {
				$out[] = $labelled;
				continue;
			}

			if ($this->isName(value: $item) === true) {
				$out[] = $item;
			}
		}

		return $out;
	}//end items()

	/**
	 * A declared `{item, label}`, or null when it is not one.
	 *
	 * @param mixed $item The authored item.
	 *
	 * @return array{item: string, label: string}|string|null The item, or its bare name without a usable label.
	 *
	 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-declared-menu-item-may-carry-the-boards-word
	 */
	private function labelledItem(mixed $item): array|string|null {
		if (is_array($item) === false || $this->isName(value: ($item['item'] ?? null)) === false) {
			return null;
		}

		$label = $this->text(value: ($item['label'] ?? ''));
		if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
			return $item['item'];
		}

		return ['item' => $item['item'], 'label' => $label];
	}//end labelledItem()

	/**
	 * The person block: the collection (`app:id`) whose first row gives the
	 * second line, and at most 4 of its fields; `[]` when it names neither.
	 *
	 * @param mixed $declared The authored person block.
	 *
	 * @return array{collection?: string, fields?: array<int, string>}
	 *
	 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-the-person-and-their-class
	 */
	private function person(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$collection = ($declared['collection'] ?? null);
		if (is_string($collection) === false || preg_match('/^[a-z0-9_-]+:[a-z0-9_-]+$/i', $collection) !== 1) {
			return [];
		}

		$fields = [];
		foreach ((array)($declared['fields'] ?? []) as $field) {
			if (is_string($field) === true && preg_match('/^[a-z0-9_]{1,80}$/i', $field) === 1) {
				$fields[] = $field;
			}
		}

		$fields = array_slice($fields, 0, 4);

		if ($fields === []) {
			return [];
		}

		return ['collection' => $collection, 'fields' => $fields];
	}//end person()

	/**
	 * The second addresses: `{address: item name}`, at most 20, an address
	 * in lower case letters, digits and hyphens.
	 *
	 * @param mixed $declared The authored addresses.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-a-portal-may-give-an-item-of-the-own-area-a-second-address
	 */
	private function routes(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$out = [];
		foreach (array_slice($declared, 0, 20, true) as $address => $name) {
			if (is_string($address) === true && preg_match(self::ADDRESS, $address) === 1
				&& $this->isName(value: $name) === true && $name !== 'overview'
			) {
				$out[$address] = $name;
			}
		}

		return $out;
	}//end routes()

	/**
	 * The phone header of the own area: `person` (the initials, no sign-out
	 * link) or '' for the site's own (mijn-phone-chrome).
	 *
	 * @param mixed $declared The authored value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/mijn-phone-chrome/specs/site-chrome/spec.md#requirement-the-own-area-may-show-the-person-in-the-phone-header
	 */
	private function phoneHeader(mixed $declared): string {
		if ($declared === 'person') {
			return 'person';
		}

		return '';
	}//end phoneHeader()

	/**
	 * Whether a value is a well-formed item name.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && preg_match(self::NAME, $value) === 1;
	}//end isName()

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
			if ($item !== 'overview' && $this->isName(value: $item) === true) {
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
