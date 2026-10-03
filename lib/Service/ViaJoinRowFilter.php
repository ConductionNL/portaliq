<?php

/**
 * Portaliq Via Join Row Filter
 *
 * Decides whether one join row of a `via` declaration still grants access.
 * A `via` MAY carry two members on top of its join (site-mijn-omgeving-components
 * REQ-SMO-023):
 *
 *     "when": {"field": "status", "in": ["active"]}
 *     "validUntilField": "expiresAt"
 *
 * A join row grants only when its `when` field holds one of the listed values,
 * and only when its `validUntilField` date is empty or not yet past. That keeps
 * a withdrawn enrolment, a terminated placement or a revoked or expired share
 * from opening anything.
 *
 * Unlike the presentation-only `rowWhen` of a row action, this is a security
 * boundary, so it fails CLOSED: an unknown key in `when`, a missing or empty
 * `in`, a non-scalar value in it, or an empty `validUntilField` makes the whole
 * declaration invalid, and the caller then reads zero rows. A date that does
 * not parse grants nothing.
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
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;

/**
 * The live-row rule of a `via` join.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
 */
class ViaJoinRowFilter {
	/**
	 * Whether the optional `when` and `validUntilField` members are well formed.
	 * Absent members are fine.
	 *
	 * @param array<string, mixed> $via The via declaration.
	 *
	 * @return bool False when either member is present and malformed.
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	public function isValid(array $via): bool {
		if (array_key_exists('when', $via) === true && $this->isValidWhen(when: $via['when']) === false) {
			return false;
		}

		if (array_key_exists('validUntilField', $via) === true
			&& (is_string($via['validUntilField']) === false || $via['validUntilField'] === '')
		) {
			return false;
		}

		return true;
	}//end isValid()

	/**
	 * Whether one join row still grants. Call only for a via that passed isValid().
	 *
	 * @param array<string, mixed>   $row The normalised join row.
	 * @param array<string, mixed>   $via The via declaration.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against; now when null.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-via-join-may-grant-only-through-live-join-rows-req-smo-023
	 */
	public function grants(array $row, array $via, ?DateTimeImmutable $now = null): bool {
		if (isset($via['when']) === true && is_array($via['when']) === true) {
			$value = $this->dotGet(row: $row, path: (string)$via['when']['field']);
			if (in_array($value, (array)$via['when']['in'], true) === false) {
				return false;
			}
		}

		if (isset($via['validUntilField']) === true) {
			return $this->stillValid(
				value: $this->dotGet(row: $row, path: (string)$via['validUntilField']),
				now: ($now ?? new DateTimeImmutable())
			);
		}

		return true;
	}//end grants()

	/**
	 * A `when` is exactly `{field, in}`: a non-empty field name and a non-empty
	 * list of scalars.
	 *
	 * @param mixed $when The declared condition.
	 *
	 * @return bool
	 */
	private function isValidWhen(mixed $when): bool {
		if (is_array($when) === false) {
			return false;
		}

		$keys = array_keys($when);
		sort($keys);
		if ($keys !== ['field', 'in']) {
			return false;
		}

		if (is_string($when['field']) === false || $when['field'] === '') {
			return false;
		}

		return $this->isScalarList(values: $when['in']);
	}//end isValidWhen()

	/**
	 * Whether a value is a non-empty list of scalars.
	 *
	 * @param mixed $values The declared `in`.
	 *
	 * @return bool
	 */
	private function isScalarList(mixed $values): bool {
		if (is_array($values) === false || $values === [] || array_is_list($values) === false) {
			return false;
		}

		return array_filter($values, static fn ($value): bool => is_scalar($value) === false) === [];
	}//end isScalarList()

	/**
	 * Whether an end date still lets the row grant. Empty grants. A date
	 * without a time is valid through that whole day. A value that does not
	 * parse grants nothing.
	 *
	 * @param mixed             $value The stored end date.
	 * @param DateTimeImmutable $now   The moment to judge against.
	 *
	 * @return bool
	 */
	private function stillValid(mixed $value, DateTimeImmutable $now): bool {
		if ($value === null || $value === '') {
			return true;
		}

		if (is_string($value) === false) {
			return false;
		}

		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			return $value >= $now->format('Y-m-d');
		}

		$until = date_create_immutable($value);
		if ($until === false) {
			return false;
		}

		return $until >= $now;
	}//end stillValid()

	/**
	 * Traverse a row by a dot path.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param string               $path The dot-separated path.
	 *
	 * @return mixed The value, or null when a segment is missing.
	 */
	private function dotGet(array $row, string $path): mixed {
		$value = $row;
		foreach (explode('.', $path) as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;
	}//end dotGet()
}//end class
