<?php

/**
 * Portaliq branch scope
 *
 * Narrows what a business session sees to the branch (vestiging) it acts for.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Branch
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
 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Branch;

/**
 * The branch rule of design D2, applied after the subject scope:
 *
 * - a session restricted to a branch (the login said so) sees only the rows
 *   of a collection whose `branchField` equals the branch, and nothing of a
 *   collection that declares no `branchField` (fail closed);
 * - a session with a chosen branch sees the rows of that branch where the
 *   collection declares `branchField`, and everything else as before;
 * - a session without a branch sees everything the subject scope lets it.
 *
 * It only ever removes rows the subject scope already allowed, so it can
 * never widen a read.
 *
 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
 */
class PortalBranchScope {
	/**
	 * Keep `branchField` only when it names a field the collection projects.
	 *
	 * A branch field the row never carries would hide every case from a
	 * branch session without anyone noticing, so it is dropped instead, and
	 * the collection then counts as declaring none.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T03
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('branchField', $collection) === false) {
			return $collection;
		}

		$field = $collection['branchField'];
		$fields = ($collection['fields'] ?? null);
		$named = (is_string($field) === true && $field !== '');
		if ($named === false || (is_array($fields) === true && in_array($field, $fields, true) === false)) {
			unset($collection['branchField']);
		}

		return $collection;
	}//end normalise()

	/**
	 * The rows of a collection the session's branch lets it see.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param array<string, mixed> $collection The declared collection.
	 * @param array<int, mixed> $rows The rows the subject scope already allowed.
	 *
	 * @return array<int, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
	 */
	public function rows(array $subject, array $collection, array $rows): array {
		if ($this->branchOf(subject: $subject) === '') {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				fn ($row): bool => (is_array($row) === true && $this->admits(subject: $subject, collection: $collection, row: $row) === true)
			)
		);
	}//end rows()

	/**
	 * Whether the session's branch lets it see one row of a collection.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param array<string, mixed> $collection The declared collection.
	 * @param array<string, mixed> $row A row the subject scope already allowed.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
	 */
	public function admits(array $subject, array $collection, array $row): bool {
		$branch = $this->branchOf(subject: $subject);
		if ($branch === '') {
			return true;
		}

		$field = (string)($collection['branchField'] ?? '');
		if ($field === '') {
			// A login restricted to one branch did not grant the whole
			// company: a collection that cannot tell branches apart shows
			// nothing. A chosen branch only filters where it can.
			return (($subject['branchRestricted'] ?? false) === true) === false;
		}

		$value = ($row[$field] ?? null);

		return (is_scalar($value) === true && (string)$value === $branch);
	}//end admits()

	/**
	 * Stamp the session's branch on a record it files, on the field the
	 * action declares as `branchField`, the way the writer stamps the
	 * subject on `scopeField`. The client's own value for that field is
	 * never kept.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 * @param array<string, mixed> $action The declared create action.
	 * @param array<string, mixed> $data The record to write.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-eherkenning-branch/tasks.md#T04
	 */
	public function stamp(array $subject, array $action, array $data): array {
		$field = $action['branchField'] ?? null;
		if (is_string($field) === false || $field === '') {
			return $data;
		}

		unset($data[$field]);
		$branch = $this->branchOf(subject: $subject);
		if ($branch !== '') {
			$data[$field] = $branch;
		}

		return $data;
	}//end stamp()

	/**
	 * The branch in effect on a session, or '' for none.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return string
	 */
	private function branchOf(array $subject): string {
		return (new BranchNumber())->normalise(value: ($subject['branch'] ?? null));
	}//end branchOf()
}//end class
