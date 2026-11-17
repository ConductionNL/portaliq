<?php

/**
 * Portaliq column overrides
 *
 * The serving portal's column settings per case type, stamped on each case
 * row as `_columns`, so the site reads a row's columns the way the portal's
 * administrator set them for that row's case type.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\CaseTypeColumns
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\CaseTypeColumns;

use OCA\Portaliq\Kernel\Contracts\ColumnOverridesInterface;
use OCA\Portaliq\Service\CaseTypeVisibility;

/**
 * Stamps a case row with its case type's column settings.
 *
 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
 */
class ColumnOverrides implements ColumnOverridesInterface {
	/**
	 * Constructor.
	 *
	 * @param array<string, array<int, array{field: string, render: string, linkLabel?: string}>> $byType The stored columns per case type id.
	 * @param CaseTypeVisibility|null $types Reads a row's case type; null with no settings.
	 */
	public function __construct(
		private readonly array $byType,
		private readonly ?CaseTypeVisibility $types,
	) {
	}//end __construct()

	/**
	 * The rows, each case row with its type's `_columns`.
	 *
	 * @param array<int, mixed> $rows The rows.
	 * @param array<string, mixed> $collection The collection they belong to.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
	 */
	public function rows(array $rows, array $collection): array {
		if ($this->byType === [] || ($collection['kind'] ?? '') !== 'cases') {
			return $rows;
		}

		$stamped = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$row = $this->row(row: $row, collection: $collection);
			}

			$stamped[] = $row;
		}

		return $stamped;
	}//end rows()

	/**
	 * One case row with its type's `_columns`, keyed by field; a row of a
	 * type with no settings is returned as it is.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $collection The collection it belongs to.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-case-type-visibility/spec.md#requirement-a-case-row-reads-with-its-case-types-columns
	 */
	public function row(array $row, array $collection): array {
		if ($this->types === null || ($collection['kind'] ?? '') !== 'cases') {
			return $row;
		}

		$columns = ($this->byType[$this->types->caseTypeOf(row: $row, collection: $collection)] ?? []);
		if ($columns !== []) {
			$row['_columns'] = array_column($columns, null, 'field');
		}

		return $row;
	}//end row()
}//end class
