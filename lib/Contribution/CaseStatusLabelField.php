<?php

/**
 * Portaliq Case Status Label Field
 *
 * A case's `status` is often a reference (a uuid) that says nothing to a
 * resident. A `cases` collection may name the field that holds the words for
 * it in `statusLabelField`; "My cases" shows those words and keeps the raw
 * status for everything that tells statuses apart.
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
 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/portal-my-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises a collection's `statusLabelField` and stamps its words on a row.
 *
 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/portal-my-cases/spec.md
 */
class CaseStatusLabelField {
	/**
	 * Keep `statusLabelField` only when it names a field the collection projects.
	 *
	 * A case's `status` is often a reference (a uuid) that says nothing to a
	 * resident. `statusLabelField` names the field that holds the words for it,
	 * so "My cases" shows those and keeps the raw status for everything else.
	 * The rule is the closed marker's: a non-empty string naming one of the
	 * projected `fields` (or any field, when the collection projects none),
	 * because a label field the row never carries would leave every status
	 * blank without anyone noticing.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/portal-my-cases/spec.md
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('statusLabelField', $collection) === false) {
			return $collection;
		}

		$field = $collection['statusLabelField'];
		$fields = ($collection['fields'] ?? null);
		$named = (is_string($field) === true && $field !== '');
		if ($named === false || (is_array($fields) === true && in_array($field, $fields, true) === false)) {
			unset($collection['statusLabelField']);
		}

		return $collection;
	}//end normalise()

	/**
	 * Stamp the words a case's status reads as onto the row as `_statusLabel`:
	 * the value of the declared `statusLabelField` when it holds text. A row
	 * without them is returned as it is, so "My cases" shows the status as
	 * before, and the raw status is never touched.
	 *
	 * @param array<string, mixed> $row The case row.
	 * @param array<string, mixed> $collection The normalised case collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/citizen-case-shows-only-its-fields/specs/portal-my-cases/spec.md
	 */
	public function stamp(array $row, array $collection): array {
		$field = (string)($collection['statusLabelField'] ?? '');
		$value = null;
		if ($field !== '') {
			$value = ($row[$field] ?? null);
		}

		if (is_string($value) === true && trim($value) !== '') {
			$row['_statusLabel'] = $value;
		}

		return $row;
	}//end stamp()
}//end class
