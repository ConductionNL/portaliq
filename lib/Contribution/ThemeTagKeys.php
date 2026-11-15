<?php

/**
 * Portaliq Theme Tag Keys
 *
 * What a contribution says about a life domain on its collections and actions.
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
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps `theme`, the product keys of a `kind: products` collection, and the
 * `when` of an action, each only when it is well formed. A bad value is
 * dropped, never repaired.
 *
 * @spec openspec/changes/life-domain-theme-pages/specs/life-domain-themes/spec.md
 */
class ThemeTagKeys {

	/**
	 * A theme slug, as it appears in an address.
	 */
	private const SLUG = '/^[a-z0-9][a-z0-9-]{0,63}$/';

	/**
	 * A field name the portal may read.
	 */
	private const FIELD = '/^[a-zA-Z][a-zA-Z0-9_]*$/';

	/**
	 * The comparisons an action's `when` may use.
	 */
	private const OPERATORS = ['eq', 'neq', 'in'];

	/**
	 * The field-name keys a `kind: products` collection may carry.
	 */
	private const FIELD_KEYS = ['titleField', 'validFromField', 'validUntilField'];

	/**
	 * Normalise a collection: `theme`, and for `kind: products` the product keys.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
	 */
	public function collection(array $collection): array {
		$collection = $this->theme(entry: $collection);
		if (($collection['kind'] ?? null) !== 'products') {
			return $this->withoutProductKeys(collection: $collection);
		}

		$collection = $this->fieldKeys(collection: $collection);
		$collection = $this->metaFields(collection: $collection);

		return $this->countLabel(collection: $collection);
	}//end collection()

	/**
	 * Drop the keys only a `kind: products` collection may carry.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutProductKeys(array $collection): array {
		foreach (array_merge(self::FIELD_KEYS, ['metaFields', 'countLabel']) as $key) {
			unset($collection[$key]);
		}

		return $collection;
	}//end withoutProductKeys()

	/**
	 * Keep each field-name key only when it names a field.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	private function fieldKeys(array $collection): array {
		foreach (self::FIELD_KEYS as $key) {
			if (array_key_exists($key, $collection) === true && $this->namesField(field: $collection[$key]) === false) {
				unset($collection[$key]);
			}
		}

		return $collection;
	}//end fieldKeys()

	/**
	 * Whether a value is a field name the portal may read.
	 *
	 * @param mixed $field The value.
	 *
	 * @return bool
	 */
	private function namesField(mixed $field): bool {
		return is_string($field) === true && preg_match(self::FIELD, $field) === 1;
	}//end namesField()

	/**
	 * Keep `metaFields` as a list of plain field names; drop it when none is left.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	private function metaFields(array $collection): array {
		if (array_key_exists('metaFields', $collection) === false) {
			return $collection;
		}

		$meta = $this->fieldNames(declared: $collection['metaFields']);
		if ($meta === []) {
			unset($collection['metaFields']);
			return $collection;
		}

		$collection['metaFields'] = $meta;

		return $collection;
	}//end metaFields()

	/**
	 * The field names in a declared list, in order; none when it is not a list.
	 *
	 * @param mixed $declared The declared `metaFields`.
	 *
	 * @return list<mixed>
	 */
	private function fieldNames(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, fn ($field): bool => $this->namesField(field: $field)));
	}//end fieldNames()

	/**
	 * Normalise an action: `theme` and `when`.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t06
	 */
	public function action(array $action): array {
		$action = $this->theme(entry: $action);
		if (array_key_exists('when', $action) === false) {
			return $action;
		}

		$when = $action['when'];
		if (is_array($when) === false || $this->soundWhen(when: $when) === false) {
			unset($action['when']);
			return $action;
		}

		$action['when'] = ['field' => $when['field'], 'op' => $when['op'], 'value' => $when['value']];

		return $action;
	}//end action()

	/**
	 * Whether a condition names a field, a known operator and a value that suits it.
	 *
	 * @param array<mixed> $when The condition.
	 *
	 * @return bool
	 */
	private function soundWhen(array $when): bool {
		if ($this->namesField(field: $when['field'] ?? null) === false || in_array($when['op'] ?? null, self::OPERATORS, true) === false) {
			return false;
		}

		return array_key_exists('value', $when) === true && $this->valueFits(op: $when['op'], value: $when['value']) === true;
	}//end soundWhen()

	/**
	 * Keep `theme` only as a slug.
	 *
	 * @param array<string, mixed> $entry A collection or an action.
	 *
	 * @return array<string, mixed>
	 */
	private function theme(array $entry): array {
		if (array_key_exists('theme', $entry) === true && (is_string($entry['theme']) === false || preg_match(self::SLUG, $entry['theme']) !== 1)) {
			unset($entry['theme']);
		}

		return $entry;
	}//end theme()

	/**
	 * Keep `countLabel` as `{singular, plural}` text.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	private function countLabel(array $collection): array {
		if (array_key_exists('countLabel', $collection) === false) {
			return $collection;
		}

		$label = $collection['countLabel'];
		if (is_array($label) === false || $this->isText(value: $label['singular'] ?? null) === false
			|| $this->isText(value: $label['plural'] ?? null) === false
		) {
			unset($collection['countLabel']);
			return $collection;
		}

		$collection['countLabel'] = ['singular' => trim($label['singular']), 'plural' => trim($label['plural'])];

		return $collection;
	}//end countLabel()

	/**
	 * Whether a value is a string with something besides whitespace in it.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isText(mixed $value): bool {
		return is_string($value) === true && trim($value) !== '';
	}//end isText()

	/**
	 * Whether a condition's value suits its operator: a list for `in`, a scalar otherwise.
	 *
	 * @param string $op The operator.
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function valueFits(string $op, mixed $value): bool {
		if ($op === 'in') {
			return is_array($value) === true && $value !== [] && array_is_list($value) === true && array_filter($value, 'is_scalar') === $value;
		}

		return is_scalar($value) === true;
	}//end valueFits()
}//end class
