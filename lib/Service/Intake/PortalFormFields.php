<?php

/**
 * Portaliq Form Fields (portal-intake-form-as-an-object)
 *
 * The fields of a published form, in order, with their presets and reference lists.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

/**
 * Reads the fields of a published form.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
 */
class PortalFormFields {
	/**
	 * Constructor.
	 *
	 * @param PortalReferenceLists|null $lists Fills a field's `options.referenceList`. Absent
	 *                                         leaves such a field with no options, which
	 *                                         closes it.
	 */
	public function __construct(
		private readonly ?PortalReferenceLists $lists = null,
	) {
	}//end __construct()

	/**
	 * The form's fields, in the order the form declares, with its presets.
	 *
	 * @param array<string, mixed> $form The published form.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md
	 */
	public function fieldsOf(array $form): array {
		$fields = ($form['fields'] ?? []);
		if (is_array($fields) === false) {
			return [];
		}

		$presets = (array)($form['presets'] ?? []);
		$out = [];
		foreach ($fields as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '') {
				continue;
			}

			if (array_key_exists($name, $presets) === true) {
				$field['preset'] = $presets[$name];
			}

			$out[] = $this->withReferenceList(field: $field);
		}

		usort(
			$out,
			static function (array $first, array $second): int {
				return ((int)($first['order'] ?? 0) <=> (int)($second['order'] ?? 0));
			}
		);

		return $out;
	}//end fieldsOf()

	/**
	 * A field that names a reference list gets that list's active items as
	 * its options. A list that comes back empty marks the field closed, so
	 * the validator refuses every value instead of accepting any.
	 *
	 * @param array<string, mixed> $field The field.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t03
	 */
	private function withReferenceList(array $field): array {
		$options = ($field['options'] ?? null);
		if (is_array($options) === false || is_string($options['referenceList'] ?? null) === false) {
			return $field;
		}

		$items = [];
		if ($this->lists !== null) {
			$items = $this->lists->items(list: $options['referenceList']);
		}

		$field['options'] = $items;
		if ($items === []) {
			$field['referenceListEmpty'] = true;
		}

		return $field;
	}//end withReferenceList()
}//end class
