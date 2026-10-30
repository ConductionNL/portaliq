<?php

/**
 * Portaliq Unique Case Rows
 *
 * Several reads of one collection, merged into one list in which every case
 * stands once. A mandated case list reads the represented party in its typed
 * and its bare form, and a case can answer both.
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
 * @spec openspec/specs/portal-mandates/spec.md#requirement-a-mandated-case-list-reads-the-party-in-its-typed-and-its-bare-form-req-mtp-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Merges row lists, keeping the first row of each case.
 *
 * @spec openspec/specs/portal-mandates/spec.md#requirement-a-mandated-case-list-reads-the-party-in-its-typed-and-its-bare-form-req-mtp-001
 */
class UniqueCaseRows {
	/**
	 * The rows of every list, each case once, in the order first read.
	 *
	 * @param array<int, array<int, mixed>> $lists The row lists.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/specs/portal-mandates/spec.md#requirement-a-mandated-case-list-reads-the-party-in-its-typed-and-its-bare-form-req-mtp-001
	 */
	public function merged(array $lists): array {
		$rows = [];
		foreach ($lists as $list) {
			foreach ($list as $row) {
				$rows[$this->keyOf(row: $row)] ??= $row;
			}
		}

		return array_values($rows);
	}//end merged()

	/**
	 * What tells one row from another: its own id, flat or in `@self`, or the
	 * row itself when it carries none.
	 *
	 * @param mixed $row The row as read.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-mandates/spec.md#requirement-a-mandated-case-list-reads-the-party-in-its-typed-and-its-bare-form-req-mtp-001
	 */
	private function keyOf(mixed $row): string {
		if (is_array($row) === false) {
			return 'row:' . (string)json_encode($row);
		}

		$self = ($row['@self'] ?? []);
		$id   = ($row['id'] ?? $row['uuid'] ?? null);
		if ($id === null && is_array($self) === true) {
			$id = ($self['id'] ?? $self['uuid'] ?? null);
		}

		if (is_scalar($id) === true && (string)$id !== '') {
			return 'id:' . (string)$id;
		}

		return 'row:' . (string)json_encode($row);
	}//end keyOf()
}//end class
