<?php

/**
 * Portaliq Portal Scope Match
 *
 * The one rule that decides whether a row's own scope field belongs to the
 * subject on a direct (non-`via`) portal path. The reader's per-row check and
 * the writer's ownership re-read both use it, so a read and a write can never
 * disagree about who owns a row.
 *
 * A stored single value matches when it is a string or an integer equal to the
 * scoping value. A stored list matches when at least one element is a string
 * or an integer equal to it (learniq `Submission.learnerRefs`,
 * `LearnerProfile.guardianRefs`). Everything else fails closed: an empty
 * scoping value, an absent or null value, an empty list, an associative array,
 * a nested list, and any other type. Comparison is strict string equality, so
 * no loose coercion can let a foreign row through.
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
 * @spec openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Shared direct-scope match for the portal reader and writer.
 *
 * @spec openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership
 */
trait PortalScopeMatch {
	/**
	 * Whether a row's stored scope value belongs to the scoping value.
	 *
	 * @param mixed $stored The value at the row's scope field (may be absent/null).
	 * @param string $scopeValue The subject's server-resolved scoping value.
	 *
	 * @return bool True for an equal single value or a list that contains it.
	 *
	 * @spec openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-direct-scope-field-must-match-a-single-value-or-strict-list-membership
	 */
	private function scopeMatches(mixed $stored, string $scopeValue): bool {
		// An empty scoping value is nobody: it must never match an empty or
		// absent field, which a plain string cast would do.
		if ($scopeValue === '') {
			return false;
		}

		if (is_array($stored) === false) {
			return $this->scopeRef(value: $stored) === $scopeValue;
		}

		// Only a real list is a membership list. An associative array (an
		// object reference such as {"value": "<uuid>"}) fails closed.
		if (array_is_list($stored) === false) {
			return false;
		}

		foreach ($stored as $member) {
			if ($this->scopeRef(value: $member) === $scopeValue) {
				return true;
			}
		}

		// An empty list, or one without the value, belongs to nobody here.
		return false;
	}//end scopeMatches()

	/**
	 * Whether a stored scope value is a membership list (the shape the writer
	 * must keep as it is on update rather than overwrite with one ref).
	 *
	 * @param mixed $stored The value at the row's scope field.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-scope-list-membership/specs/portal-contribution-contract/spec.md#requirement-a-write-must-keep-a-verified-list-and-stamp-a-list-for-an-array-scope-field
	 */
	private function isScopeList(mixed $stored): bool {
		return is_array($stored) === true && array_is_list($stored) === true;
	}//end isScopeList()

	/**
	 * Normalise one scope value to a comparable reference: a string as is, an
	 * integer as its decimal string, anything else (null, float, bool, array,
	 * object) to null so it can never equal a scoping value.
	 *
	 * @param mixed $value The stored single value or list element.
	 *
	 * @return string|null
	 */
	private function scopeRef(mixed $value): ?string {
		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		return null;
	}//end scopeRef()
}//end trait
