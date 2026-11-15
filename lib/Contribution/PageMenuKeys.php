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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the menu, record-switcher, home and badge keys of one page.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
 */
class PageMenuKeys {
	/**
	 * The longest badge label.
	 */
	private const MAX_BADGE_LABEL = 80;

	/**
	 * The keys to add to a normalised page entry.
	 *
	 * @param array<string, mixed> $page          The declared page.
	 * @param array<string, mixed> $entry         The page as normalised so far (reads `record`).
	 * @param array<int, string>   $collectionIds The contribution's collection ids.
	 *
	 * @return array<string, mixed> The kept keys, possibly none.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-place-itself-in-the-menu-per-record-or-as-home-req-smo-020
	 */
	public function keys(array $page, array $entry, array $collectionIds): array {
		$out     = $this->flags(page: $page);
		$records = $this->records(declared: ($page['records'] ?? null), collectionIds: $collectionIds);
		if ($records !== null) {
			$out['records'] = $records;
		}

		$out += $this->perRecord(
			declared: ($page['perRecord'] ?? null),
			recordCollections: [($entry['record']['collection'] ?? null), ($records['collection'] ?? null)]
		);

		return $out + $this->badge(declared: ($page['badge'] ?? null), collectionIds: $collectionIds);
	}//end keys()

	/**
	 * The page's menu flags: `menu` only when it is false, `home` only when
	 * it is true.
	 *
	 * @param array<string, mixed> $page The declared page.
	 *
	 * @return array<string, bool>
	 */
	private function flags(array $page): array {
		$out = [];
		if (array_key_exists('menu', $page) === true && $page['menu'] === false) {
			$out['menu'] = false;
		}

		if (array_key_exists('home', $page) === true && $page['home'] === true) {
			$out['home'] = true;
		}

		return $out;
	}//end flags()

	/**
	 * The `perRecord` key, kept only when it names the page's record
	 * collection or its records collection.
	 *
	 * @param mixed             $declared          The declared value.
	 * @param array<int, mixed> $recordCollections The page's record collection and records collection.
	 *
	 * @return array<string, string>
	 */
	private function perRecord(mixed $declared, array $recordCollections): array {
		$named = array_filter($recordCollections, static fn ($id): bool => is_string($id) === true && $id !== '');
		if (is_string($declared) === false || in_array($declared, $named, true) === false) {
			return [];
		}

		return ['perRecord' => $declared];
	}//end perRecord()

	/**
	 * The `badge` declaration: a count in the menu entry of how many rows a
	 * collection of the same contribution holds ("Oudergesprekken 1"), with
	 * an optional `label` for screen readers in which `{count}` is filled in.
	 * Nothing when it names no collection of the contribution, so a badge can
	 * never count another app's rows.
	 *
	 * @param mixed              $declared      The declared value.
	 * @param array<int, string> $collectionIds The contribution's collection ids.
	 *
	 * @return array<string, array{collection: string, label?: string}> `['badge' => ...]`, or [] to drop it.
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-page-may-show-a-count-in-its-menu-entry
	 */
	private function badge(mixed $declared, array $collectionIds): array {
		if (is_array($declared) === false || in_array(($declared['collection'] ?? null), $collectionIds, true) === false) {
			return [];
		}

		$out   = ['collection' => $declared['collection']];
		$label = ($declared['label'] ?? null);
		if (is_string($label) === true && trim($label) !== '' && mb_strlen(trim($label)) <= self::MAX_BADGE_LABEL) {
			$out['label'] = trim($label);
		}

		return ['badge' => $out];
	}//end badge()

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

		return ['collection' => $declared['collection']]
			+ $this->fieldLists(declared: $declared)
			+ $this->subtitleLookup(lookup: ($declared['subtitleLookup'] ?? null), collectionIds: $collectionIds);
	}//end records()

	/**
	 * The records' title and subtitle field lists, each only when it names a
	 * field.
	 *
	 * @param array<string, mixed> $declared The declared records.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function fieldLists(array $declared): array {
		$out = [];
		foreach (['titleFields', 'subtitleFields'] as $listKey) {
			$names = $this->names(value: ($declared[$listKey] ?? null));
			if ($names !== []) {
				$out[$listKey] = $names;
			}
		}

		return $out;
	}//end fieldLists()

	/**
	 * A subtitle from one related record, the child's group (REQ-SMO-026):
	 * kept only when it names a collection of the contribution and both of
	 * its fields.
	 *
	 * @param mixed              $lookup        The declared lookup.
	 * @param array<int, string> $collectionIds The contribution's collection ids.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function subtitleLookup(mixed $lookup, array $collectionIds): array {
		if (is_array($lookup) === false
			|| in_array(($lookup['collection'] ?? null), $collectionIds, true) === false
			|| count($this->names(value: [($lookup['matchField'] ?? null), ($lookup['valueField'] ?? null)])) !== 2
		) {
			return [];
		}

		return ['subtitleLookup' => ['collection' => $lookup['collection'], 'matchField' => $lookup['matchField'], 'valueField' => $lookup['valueField']]];
	}//end subtitleLookup()

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
