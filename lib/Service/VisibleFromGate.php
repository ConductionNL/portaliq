<?php

/**
 * Visible From Gate (site-school-blocks)
 *
 * A collection MAY declare `visibleFromField`: a projected field holding a
 * date or date-time before which a row is not shown to the subject. A grade
 * notice prepared on Friday for Monday, or a report card released on a set
 * day, stays out of every list, every inbox and every read by id until that
 * moment has passed on the server's clock. The browser's clock decides
 * nothing: a row hidden here never reaches it.
 *
 * A row whose field is empty or not a date shows: the field states WHEN a row
 * may show, so a row that names no moment has no reason to wait. A row of a
 * collection that declares no field is untouched.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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

namespace OCA\Portaliq\Service;

/**
 * Leaves out the rows whose moment has not come.
 *
 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
 */
class VisibleFromGate {
	/**
	 * The rows the subject may see now, in order.
	 *
	 * @param array<int, mixed>    $rows       The rows read.
	 * @param array<string, mixed> $collection The collection, with its `visibleFromField`.
	 * @param int|null             $now        The moment, a Unix time; null for the server clock.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
	 */
	public function rows(array $rows, array $collection, ?int $now=null): array {
		$field = ($collection['visibleFromField'] ?? null);
		if (is_string($field) === false || $field === '') {
			return $rows;
		}

		$moment = ($now ?? time());

		return array_values(
			array_filter(
				$rows,
				fn ($row): bool => $this->isVisible(row: $row, field: $field, now: $moment)
			)
		);
	}//end rows()

	/**
	 * Whether one row may be seen now.
	 *
	 * @param mixed  $row   The row.
	 * @param string $field The field with the moment.
	 * @param int    $now   The moment, a Unix time.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-school-blocks/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-keep-a-row-back-until-its-moment-has-passed
	 */
	public function isVisible(mixed $row, string $field, int $now): bool {
		if (is_array($row) === false) {
			return true;
		}

		$value = ($row[$field] ?? null);
		if (is_string($value) === false || trim($value) === '') {
			return true;
		}

		$from = strtotime(trim($value));
		if ($from === false) {
			return true;
		}

		return $from <= $now;
	}//end isVisible()
}//end class
