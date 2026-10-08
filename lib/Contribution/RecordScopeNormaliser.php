<?php

/**
 * Portaliq Record Scope Normaliser (contribution-record-page)
 *
 * How a block or calendar source narrows its rows on a record page, and the
 * lookups that copy one value from a second collection onto each row:
 *
 * - `recordField` (+ `recordKey`, default `id`): a row stays when that field
 *   equals, or holds, the open record's value;
 * - `recordGroupsField`: a row bound to groups stays only for the record's
 *   groups (or, without a record, the groups of every row of the
 *   contribution's `guardianAudience.groups` collection);
 * - `lookups`: `{as, collection, matchField, valueField, recordField?,
 *   values?, fallback?}` writes, under `as`, the value found in another
 *   collection of the same contribution (a homework row's "handed in").
 *
 * SECURITY: presentation only. Every lookup collection must resolve against
 * the trust-filtered collections of the same contribution, and every key only
 * ever leaves rows out or labels rows the server already scoped.
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
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates the record scope keys and lookups, fail-closed.
 *
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */
class RecordScopeNormaliser {
	/**
	 * Copy a valid `recordField`, `recordKey` and `recordGroupsField` from a
	 * declared entry onto a normalised one. `recordKey` is only kept beside a
	 * `recordField`.
	 *
	 * @param array<string, mixed> $declared The declared block or source.
	 * @param array<string, mixed> $entry The normalised block or source.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-block-on-a-record-page-may-narrow-its-rows-to-the-open-record
	 */
	public function scope(array $declared, array $entry): array {
		if ($this->isName(value: ($declared['recordField'] ?? null)) === true) {
			$entry['recordField'] = $declared['recordField'];
			if ($this->isName(value: ($declared['recordKey'] ?? null)) === true) {
				$entry['recordKey'] = $declared['recordKey'];
			}
		}

		if ($this->isName(value: ($declared['recordGroupsField'] ?? null)) === true) {
			$entry['recordGroupsField'] = $declared['recordGroupsField'];
		}

		return $entry;
	}//end scope()

	/**
	 * Copy the well-formed `lookups` of a declared block onto a normalised one.
	 *
	 * @param array<string, mixed> $declared The declared block.
	 * @param array<string, mixed> $entry The normalised block.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-label-its-rows-from-a-second-collection
	 */
	public function lookups(array $declared, array $entry, array $collectionIds): array {
		$lookups = [];
		foreach ((array)($declared['lookups'] ?? []) as $lookup) {
			$normalised = $this->lookup(lookup: $lookup, collectionIds: $collectionIds);
			if ($normalised !== null) {
				$lookups[] = $normalised;
			}
		}

		if ($lookups !== []) {
			$entry['lookups'] = $lookups;
		}

		return $entry;
	}//end lookups()

	/**
	 * One lookup, or null when it misses a name or its collection does not resolve.
	 *
	 * @param mixed $lookup The declared lookup.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lookup(mixed $lookup, array $collectionIds): ?array {
		if (is_array($lookup) === false || in_array(($lookup['collection'] ?? null), $collectionIds, true) === false) {
			return null;
		}

		foreach (['as', 'matchField', 'valueField'] as $key) {
			if ($this->isName(value: ($lookup[$key] ?? null)) === false) {
				return null;
			}
		}

		$out = [
			'as' => $lookup['as'],
			'collection' => $lookup['collection'],
			'matchField' => $lookup['matchField'],
			'valueField' => $lookup['valueField'],
		];
		if ($this->isName(value: ($lookup['recordField'] ?? null)) === true) {
			$out['recordField'] = $lookup['recordField'];
		}

		$values = array_filter(
			(array)($lookup['values'] ?? []),
			fn ($label, $value): bool => is_string($value) === true && $this->isName(value: $label),
			ARRAY_FILTER_USE_BOTH
		);
		if ($values !== []) {
			$out['values'] = $values;
		}

		if ($this->isName(value: ($lookup['fallback'] ?? null)) === true) {
			$out['fallback'] = $lookup['fallback'];
		}

		return $out;
	}//end lookup()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isName()
}//end class
