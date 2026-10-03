<?php

/**
 * Portaliq Page Menu Keys
 *
 * The page keys that place a contributed page in the resident's menu, per
 * record, or as home (site-mijn-omgeving-components REQ-SMO-020):
 *
 *     "menu": false                     the page keeps its route, leaves the menu
 *     "records": {"collection": "children", "titleFields": [...], "subtitleFields": [...]}
 *     "perRecord": "children"           one menu entry per row of that collection
 *     "home": true                      the page opens /mijn
 *
 * Every key is kept only in its one valid form and dropped otherwise, so a
 * typo leaves the page as it was without the key. `records` also accepts a
 * bare collection id. `perRecord` is kept only when the page is a record page
 * (`record` or `records`) on the same collection. The menu group (`group`) is
 * #1097's, in PortalPageResolver::menuGroup().
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the menu, record-switcher and home keys of one page.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
class PageMenuKeys {
	/**
	 * The keys to add to a normalised page entry.
	 *
	 * @param array<string, mixed> $page          The declared page.
	 * @param array<string, mixed> $entry         The page as normalised so far (reads `record`).
	 * @param array<int, string>   $collectionIds The contribution's collection ids.
	 *
	 * @return array<string, mixed> The kept keys, possibly none.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
	 */
	public function keys(array $page, array $entry, array $collectionIds): array {
		$out = [];

		if (array_key_exists('menu', $page) === true && $page['menu'] === false) {
			$out['menu'] = false;
		}

		if (array_key_exists('home', $page) === true && $page['home'] === true) {
			$out['home'] = true;
		}

		$records = $this->records(declared: ($page['records'] ?? null), collectionIds: $collectionIds);
		if ($records !== null) {
			$out['records'] = $records;
		}

		$perRecord = ($page['perRecord'] ?? null);
		$recordCollections = array_filter(
			[
				($entry['record']['collection'] ?? null),
				($records['collection'] ?? null),
			],
			static fn ($id): bool => is_string($id) === true && $id !== ''
		);
		if (is_string($perRecord) === true && in_array($perRecord, $recordCollections, true) === true) {
			$out['perRecord'] = $perRecord;
		}

		return $out;
	}//end keys()

	/**
	 * The `records` declaration, or null when it names no collection of the
	 * contribution.
	 *
	 * @param mixed              $declared      The declared value.
	 * @param array<int, string> $collectionIds The contribution's collection ids.
	 *
	 * @return array<string, mixed>|null
	 */
	private function records(mixed $declared, array $collectionIds): ?array {
		if (is_string($declared) === true) {
			$declared = ['collection' => $declared];
		}

		if (is_array($declared) === false || in_array(($declared['collection'] ?? null), $collectionIds, true) === false) {
			return null;
		}

		$out = ['collection' => $declared['collection']];
		foreach (['titleFields', 'subtitleFields'] as $listKey) {
			$names = $this->names(value: ($declared[$listKey] ?? null));
			if ($names !== []) {
				$out[$listKey] = $names;
			}
		}

		return $out;
	}//end records()

	/**
	 * The non-empty strings of a list, in order.
	 *
	 * @param mixed $value The declared list.
	 *
	 * @return array<int, string>
	 */
	private function names(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(
			array_filter($value, static fn ($name): bool => is_string($name) === true && $name !== '')
		);
	}//end names()
}//end class
