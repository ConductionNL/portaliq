<?php

/**
 * Portaliq Portal Field Projector
 *
 * The declared-`fields` projection primitive (field-projection change), split
 * out of PortalObjectReader so the READ boundary (scope resolution, per-row
 * ownership verification, the one-hop join) and the SHAPING boundary (what a
 * verified row shows) are separate collaborators.
 *
 * SECURITY: projection runs AFTER per-row verification and never decides which
 * rows return, only what a returned row shows. A malformed declaration fails
 * closed NARROW (identifiers only), never open to the full row (ADR-005) —
 * failing open would leak exactly the staff-only fields the contributor tried
 * to hide.
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
 * @spec openspec/changes/field-projection/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use Psr\Log\LoggerInterface;

/**
 * Projects verified rows down to a declared `fields` whitelist, fail-closed.
 *
 * @spec openspec/changes/field-projection/tasks.md#T1
 */
class PortalFieldProjector {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Project every verified row down to the declared fields whitelist.
	 *
	 * `$fields === null` means "no projection declared" — rows pass through
	 * whole (backward compatible). Anything else is handed to projectRow()
	 * per row, including malformed declarations (which fail closed there).
	 *
	 * @param array<int, array<string, mixed>> $rows The verified rows.
	 * @param mixed $fields The raw `fields` declaration.
	 *
	 * @return array<int, array<string, mixed>> The projected rows.
	 *
	 * @spec openspec/changes/field-projection/tasks.md#T1
	 */
	public function projectRows(array $rows, mixed $fields): array {
		if ($fields === null) {
			return $rows;
		}

		$projected = [];
		foreach ($rows as $row) {
			$projected[] = $this->projectRow(row: $row, fields: $fields);
		}

		return $projected;
	}//end projectRows()

	/**
	 * Project a single verified row down to the declared fields whitelist.
	 *
	 * THE single-row projection primitive, so any single-object/detail read
	 * applies identical semantics by calling it before returning. Semantics
	 * (field-projection change):
	 *
	 * - `$fields === null` → the row passes through whole (no declaration).
	 * - Pure whitelist: only declared property names that exist as TOP-LEVEL
	 *   row keys are kept; unknown declared names are simply absent (a stale
	 *   manifest never becomes an error). No dot-path interpretation.
	 * - The row identifier(s) are NEVER stripped: flat `id`/`uuid` survive
	 *   when present, and an `@self` envelope reduces to only its `id`/`uuid`
	 *   members (declare `"@self"` explicitly to keep the full envelope) —
	 *   detail links keep working while envelope metadata stays suppressed.
	 * - A malformed declaration (non-array, or non-string entries) projects
	 *   to identifiers-only: a declared projection intent fails closed
	 *   NARROW, never open to the full row (ADR-005).
	 * - `scopeField` values are not auto-included; declare them if wanted.
	 *
	 * @param array<string, mixed> $row The verified, normalised row.
	 * @param mixed $fields The raw `fields` declaration.
	 *
	 * @return array<string, mixed> The projected row.
	 *
	 * @spec openspec/changes/field-projection/tasks.md#T1
	 */
	public function projectRow(array $row, mixed $fields): array {
		if ($fields === null) {
			return $row;
		}

		$projected = [];
		foreach ($this->fieldWhitelist(fields: $fields) as $field) {
			if (array_key_exists($field, $row) === true) {
				$projected[$field] = $row[$field];
			}
		}

		return $this->preserveIdentifiers(row: $row, projected: $projected);
	}//end projectRow()

	/**
	 * Re-attach the row identifier(s) a projection must never strip (detail
	 * links depend on them): flat `id`/`uuid` pass through, and an `@self`
	 * envelope — unless explicitly declared — reduces to its `id`/`uuid`
	 * members only, so envelope metadata stays suppressed.
	 *
	 * @param array<string, mixed> $row The original verified row.
	 * @param array<string, mixed> $projected The whitelisted projection so far.
	 *
	 * @return array<string, mixed> The projection with identifiers preserved.
	 *
	 * @spec openspec/changes/field-projection/tasks.md#T1
	 */
	private function preserveIdentifiers(array $row, array $projected): array {
		foreach (['id', 'uuid'] as $idKey) {
			if (array_key_exists($idKey, $row) === true && array_key_exists($idKey, $projected) === false) {
				$projected[$idKey] = $row[$idKey];
			}
		}

		if (array_key_exists('@self', $projected) === true || is_array(($row['@self'] ?? null)) === false) {
			return $projected;
		}

		$self = [];
		foreach (['id', 'uuid'] as $idKey) {
			if (array_key_exists($idKey, $row['@self']) === true) {
				$self[$idKey] = $row['@self'][$idKey];
			}
		}

		if (count($self) > 0) {
			$projected['@self'] = $self;
		}

		return $projected;
	}//end preserveIdentifiers()

	/**
	 * Normalise a raw `fields` declaration to a list of usable property
	 * names: only non-empty strings survive. A malformed declaration (not an
	 * array at all) yields the empty whitelist — identifiers-only downstream.
	 *
	 * @param mixed $fields The raw `fields` declaration.
	 *
	 * @return array<int, string>
	 */
	private function fieldWhitelist(mixed $fields): array {
		$whitelist = [];
		if (is_array($fields) === true) {
			foreach ($fields as $field) {
				if (is_string($field) === true && $field !== '') {
					$whitelist[] = $field;
				}
			}
		}

		if (count($whitelist) === 0) {
			$this->logger->debug('Portaliq: fields declaration yielded an empty whitelist — projecting to identifiers only');
		}

		return $whitelist;
	}//end fieldWhitelist()
}//end class
