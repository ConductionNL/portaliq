<?php

/**
 * Pure helpers over portal rows and join references: dotted reads, row ids,
 * target refs, via declarations, scope filters and tenant checks.
 *
 * Moved out of PortalObjectReader unchanged so the reader, which much of the
 * app calls, keeps only its read flow. None of them touches state.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\Service\Tenancy\SchemaTenancy;

/**
 * Stateless row and reference helpers for the portal object reader.
 */
final class PortalRowRefs {

	/**
	 * Traverse a row by a dot path (e.g. `betrokkeneIdentificatie.inpBsn`).
	 *
	 * @param array<string, mixed> $row The normalised row.
	 * @param string $path The dot-separated path.
	 *
	 * @return mixed The value at the path, or null when any segment is missing.
	 */
	public function dotGet(array $row, string $path): mixed {
		$value = $row;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}//end dotGet()

	/**
	 * Collect a row's identifier candidates (`id` / `uuid`, flat or in the
	 * `@self` envelope) for join-set membership checks.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return array<int, string>
	 */
	public function rowIds(array $row): array {
		$self = ($row['@self'] ?? null);
		$candidates = [
			($row['id'] ?? null),
			($row['uuid'] ?? null),
		];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? null);
			$candidates[] = ($self['uuid'] ?? null);
		}

		$ids = [];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				$ids[] = (string)$candidate;
			}
		}

		return $ids;
	}//end rowIds()

	/**
	 * Normalise a targetField value to a list of non-empty string references
	 * (string or array of strings accepted — OR relations convention).
	 *
	 * @param mixed $value The raw targetField value.
	 *
	 * @return array<int, string>
	 */
	public function targetRefs(mixed $value): array {
		if (is_string($value) === true && $value !== '') {
			return [$value];
		}

		$refs = [];
		if (is_array($value) === true) {
			foreach ($value as $ref) {
				if (is_string($ref) === true && $ref !== '') {
					$refs[] = $ref;
				}
			}
		}

		return $refs;
	}//end targetRefs()

	/**
	 * Whether one outer row is a member of the verified target set under the
	 * declared `match` mode. Both branches use strict set membership over the
	 * SAME `array<string, true>` set the join pre-pass built, so nothing loose
	 * can slip in and an empty set can only ever exclude.
	 *
	 * - `scopeField` (reverse, v2.2): the outer row carries a foreign scope
	 *   key. It is a member when the value at its `$scopeField` (dot-path) is
	 *   in the set — scalar equality, OR strict array-contains for a
	 *   multi-value field (ANY element in the set matches). An absent/null
	 *   value normalises to no candidates → excluded, never a wildcard.
	 * - `id` (forward, DEFAULT): the outer row's OWN id/uuid is in the set —
	 *   the original A5 behaviour, byte-for-byte.
	 *
	 * @param array<string, mixed> $row The normalised outer row.
	 * @param array<string, true> $targets The verified target set.
	 * @param string $match 'id' or 'scopeField'.
	 * @param string $scopeField The outer collection's scope field.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
	 */
	public function rowInTargetSet(array $row, array $targets, string $match, string $scopeField): bool {
		if ($match === 'scopeField') {
			// Reuse the same string/array-of-strings normalisation the target
			// set itself was built from (targetRefs), so the two sides compare
			// like-for-like under strict membership.
			foreach ($this->targetRefs(value: $this->dotGet(row: $row, path: $scopeField)) as $key) {
				if (isset($targets[$key]) === true) {
					return true;
				}
			}

			return false;
		}

		foreach ($this->rowIds(row: $row) as $id) {
			if (isset($targets[$id]) === true) {
				return true;
			}
		}

		return false;
	}//end rowInTargetSet()

	/**
	 * Per-row dot-path verification of a join row against the scoping value
	 * (scalar equality, or strict containment for multi-value properties).
	 *
	 * @param array<string, mixed> $row The normalised join row.
	 * @param string $path The dot path to the scope property.
	 * @param string $scopeValue The subject's scoping value.
	 *
	 * @return bool
	 */
	public function joinRowMatches(array $row, string $path, string $scopeValue): bool {
		$value = $this->dotGet(row: $row, path: $path);
		if ($value === $scopeValue) {
			return true;
		}

		if (is_array($value) === true) {
			return in_array($scopeValue, $value, true);
		}

		return false;
	}//end joinRowMatches()

	/**
	 * Structural validation of a `via` declaration: an array carrying
	 * non-empty string register/schema/scopeField/targetField members and NO
	 * nested `via` (one hop maximum — contract law, ADR-046 A5). The optional
	 * `match` discriminator (v2.2) is validated too: absent is fine (defaults
	 * to forward `id`), but when present it MUST be exactly `'id'` or
	 * `'scopeField'` — any other value fails the via closed (→ zero rows).
	 *
	 * @param mixed $via The raw declaration.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-07-reverse-scope-join/tasks.md#T1
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	public function isValidVia(mixed $via): bool {
		if (is_array($via) === false) {
			return false;
		}

		if (array_key_exists('via', $via) === true) {
			return false;
		}

		foreach (['register', 'schema', 'scopeField', 'targetField'] as $member) {
			if (is_string(($via[$member] ?? null)) === false || $via[$member] === '') {
				return false;
			}
		}

		if (array_key_exists('match', $via) === true
			&& in_array($via['match'], ['id', 'scopeField'], true) === false
		) {
			return false;
		}

		// The live-row members (REQ-SMO-023): malformed fails the whole via.
		return (new ViaJoinRowFilter())->isValid(via: $via);
	}//end isValidVia()

	/**
	 * Build the OpenRegister property filters for a scoped collection read.
	 *
	 * A declared collection `filter` narrows the read to a subset of the
	 * schema. It exists for SUPERTYPE schemas that carry several logical kinds
	 * behind a discriminator (e.g. pipelinq's `ticket` schema, whose
	 * `ticketType` separates requests / complaints / contact moments): without
	 * it, three collections over one schema would each return all three kinds.
	 *
	 * It NARROWS only — the scope filter is applied LAST and always wins, so a
	 * declared filter can never widen past the scope boundary.
	 *
	 * @param array<string, mixed> $filter The declared narrowing filter.
	 * @param string $scopeField The row field that must equal the scope value.
	 * @param string $scopeValue The resolved scope value.
	 *
	 * @return array<string, mixed> The filters to hand to OpenRegister.
	 */
	public function scopedFilters(array $filter, string $scopeField, string $scopeValue): array {
		$filters = [];
		foreach ($filter as $key => $value) {
			if (is_string($key) === true && $key !== '') {
				$filters[$key] = $value;
			}
		}

		if ($scopeField !== '' && $scopeValue !== '') {
			$filters[$scopeField] = $scopeValue;
		}

		return $filters;
	}//end scopedFilters()

	/**
	 * The per-row tenant check for a read that is ALSO scoped to the subject:
	 * enforced only when both the subject and the row carry an organisation.
	 * A schema without an organisation (e.g. procest supplierTender) is scoped
	 * by the subject reference alone, which is globally unique, so its rows
	 * pass here after the subject check already held them to the subject.
	 * A read WITHOUT a subject scope must use tenantMatches() instead.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 * @param string $organisation The expected tenant (empty = skip).
	 * @param string $schema The schema read, when known; an organisation-scoped one refuses a missing tenant value.
	 *
	 * @return bool
	 */
	public function organisationMatches(array $row, string $organisation, string $schema=''): bool {
		$rowOrganisation = (string)($row['organisation'] ?? '');
		// A read that names no tenant is a system lookup by reference or secret and is
		// left as it was. When it names one, a schema declared organisation-scoped
		// refuses a row without a tenant instead of passing it for every tenant
		// (operate-portals-per-organisation REQ-OPO-002).
		if ($organisation === '') {
			return true;
		}

		if ($schema !== '' && (new SchemaTenancy())->declaresOrganisation(schema: $schema) === true) {
			return $rowOrganisation === $organisation;
		}

		return $rowOrganisation === '' || $rowOrganisation === $organisation;
	}//end organisationMatches()

	/**
	 * The per-row tenant check for a read WITHOUT a subject scope that names a
	 * tenant, such as the polls for an audience and organisation. The tenant
	 * is its only boundary, so it fails closed: a row without an organisation
	 * belongs to no tenant and is dropped, instead of passing for every one
	 * (portaliq#801, operate-portals-per-organisation D2). A read that names
	 * no tenant is a system lookup by id or secret and is left as it was.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 * @param string $organisation The tenant the read names (empty = none named).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md
	 */
	public function tenantMatches(array $row, string $organisation): bool {
		if ($organisation === '') {
			return true;
		}

		return (string)($row['organisation'] ?? '') === $organisation;
	}//end tenantMatches()
}//end class
