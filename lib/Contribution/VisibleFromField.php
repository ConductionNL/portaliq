<?php

/**
 * Portaliq Visible From Field (site-school-blocks)
 *
 * Normalises a collection's `visibleFromField`, the field VisibleFromGate
 * reads to keep a row back until its moment has passed.
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
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a well-formed `visibleFromField` and projects it.
 *
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
 */
class VisibleFromField {
	/**
	 * Keep `visibleFromField` when it names a field, and make sure the
	 * collection projects it (site-school-blocks): the gate reads it from the
	 * projected row, and a field the projection dropped would show every row
	 * early. Fails closed: the field is added to `fields`, never ignored.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('visibleFromField', $collection) === false) {
			return $collection;
		}

		$field = $collection['visibleFromField'];
		if (is_string($field) === false || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field) !== 1) {
			unset($collection['visibleFromField']);
			return $collection;
		}

		if (is_array($collection['fields'] ?? null) === true && in_array($field, $collection['fields'], true) === false) {
			$collection['fields'][] = $field;
		}

		return $collection;
	}//end normalise()
}//end class
