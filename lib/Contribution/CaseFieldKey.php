<?php

/**
 * Portaliq Case Field Key (case-page-tasks-decision-dates-and-next-step)
 *
 * A tasks collection may name `caseField`, the field that holds the reference
 * of the case a task belongs to. The case page lists the open tasks whose
 * value equals the case's reference, from every collection that names it.
 *
 * The key is kept only when it names a field the collection projects (any
 * field, when it projects none). A key on a field the rows never carry would
 * match no task, or every task, and the case page would show the wrong ones.
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
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps `caseField` on a collection when it names a projected field.
 *
 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t02
 */
class CaseFieldKey {
	/**
	 * Keep `caseField` when it fits; drop it otherwise.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/case-page-tasks-decision-dates-and-next-step/tasks.md#t02
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('caseField', $collection) === false) {
			return $collection;
		}

		$field  = $collection['caseField'];
		$fields = ($collection['fields'] ?? null);
		$named  = (is_string($field) === true && trim($field) !== '');
		if ($named === false || (is_array($fields) === true && in_array($field, $fields, true) === false)) {
			unset($collection['caseField']);
		}

		return $collection;
	}//end normalise()
}//end class
