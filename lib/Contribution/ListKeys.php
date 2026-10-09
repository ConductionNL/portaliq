<?php

/**
 * Portaliq List Keys (mijn-lists-follow-the-boards)
 *
 * The keys a collection block may carry to read as the school boards' lists,
 * whatever its display:
 * - `rowPage`: a page of the same contribution that shows one row; each row
 *   then links to it (`/mijn/<app>/<page>/<id>`) with a chevron. `rowIdField`
 *   names the field that holds the id to open (a grade's subject), else the
 *   row's own id is used;
 * - `tabs`: at most 6 `{label, field?, values?}`; the first tab is chosen at
 *   first, a tab with a field shows the rows whose value is one of `values`,
 *   a tab without one shows every row ("Komend", "Afgerond", "Geannuleerd").
 * A key that does not fit is dropped.
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
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-row-may-open-its-own-page
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the row link and the tabs of a collection block.
 *
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-row-may-open-its-own-page
 */
class ListKeys {

	/**
	 * The most tabs one block may show.
	 */
	private const MAX_TABS = 6;

	/**
	 * The most values one tab may match.
	 */
	private const MAX_VALUES = 20;

	/**
	 * The longest tab label.
	 */
	private const MAX_LABEL = 40;

	/**
	 * The row link and tabs of one collection block.
	 *
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection it reads, or null when unknown.
	 * @param array<int, string>        $pageIds    The contribution's page ids.
	 *
	 * @return array<string, mixed> `{rowPage?, rowIdField?, tabs?}`.
	 *
	 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-row-may-open-its-own-page
	 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
	 */
	public function keys(array $block, ?array $collection, array $pageIds): array {
		$out = [];
		$page = ($block['rowPage'] ?? null);
		if (is_string($page) === true && in_array($page, $pageIds, true) === true) {
			$out['rowPage'] = $page;
			if ($this->projects(collection: $collection, field: ($block['rowIdField'] ?? null)) === true) {
				$out['rowIdField'] = $block['rowIdField'];
			}
		}

		$tabs = $this->tabs(declared: ($block['tabs'] ?? null), collection: $collection);
		if (count($tabs) > 1) {
			$out['tabs'] = $tabs;
		}

		return $out;
	}//end keys()

	/**
	 * The well-formed tabs, at most six.
	 *
	 * @param mixed                     $declared   The declared tabs.
	 * @param array<string, mixed>|null $collection The collection.
	 *
	 * @return array<int, array{label: string, field?: string, values?: array<int, string>}>
	 *
	 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
	 */
	private function tabs(mixed $declared, ?array $collection): array {
		$tabs = [];
		foreach ((array)$declared as $tab) {
			$entry = $this->tab(tab: $tab, collection: $collection);
			if ($entry !== null) {
				$tabs[] = $entry;
			}
		}

		return array_slice($tabs, 0, self::MAX_TABS);
	}//end tabs()

	/**
	 * One tab, or null when its label does not fit or it names a field
	 * the collection does not project, or no values for it.
	 *
	 * @param mixed                     $tab        The declared tab.
	 * @param array<string, mixed>|null $collection The collection.
	 *
	 * @return array{label: string, field?: string, values?: array<int, string>}|null
	 */
	private function tab(mixed $tab, ?array $collection): ?array {
		if (is_array($tab) === false) {
			return null;
		}

		$label = ($tab['label'] ?? null);
		if (is_string($label) === false || trim($label) === '' || mb_strlen(trim($label)) > self::MAX_LABEL) {
			return null;
		}

		if (isset($tab['field']) === false) {
			return ['label' => trim($label)];
		}

		$values = array_values(array_filter((array)($tab['values'] ?? []), static fn ($value): bool => is_string($value) === true && $value !== ''));
		if ($this->projects(collection: $collection, field: $tab['field']) === false || $values === []) {
			return null;
		}

		return ['label' => trim($label), 'field' => $tab['field'], 'values' => array_slice($values, 0, self::MAX_VALUES)];
	}//end tab()

	/**
	 * Whether a field is one the collection projects.
	 *
	 * @param array<string, mixed>|null $collection The collection.
	 * @param mixed                     $field      The field.
	 *
	 * @return bool
	 */
	private function projects(?array $collection, mixed $field): bool {
		if (is_string($field) === false || $field === '' || $collection === null) {
			return false;
		}

		$fields = ($collection['fields'] ?? null);
		return is_array($fields) === false || in_array($field, $fields, true) === true;
	}//end projects()
}//end class
