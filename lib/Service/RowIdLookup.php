<?php

/**
 * Row Id Lookup
 *
 * Reads a normalised row's identifier: `uuid` or `id`, flat or in `@self`.
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
 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The first non-empty string or integer id of a row, in one of two orders:
 * uuid first (the flat `uuid`, the flat `id`, then `@self.uuid` and
 * `@self.id`) or id first (the flat `id`, the flat `uuid`, then `@self.id`
 * and `@self.uuid`).
 *
 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
 */
final class RowIdLookup {
	/**
	 * Extract a row's identifier (`id`/`uuid`, flat or in `@self`), or null.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return string|null
	 *
	 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	public function firstUuidOrId(array $row): ?string {
		$self = $this->selfOf(row: $row);
		return $this->firstOf(candidates: [($row['uuid'] ?? null), ($row['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)]);
	}//end firstUuidOrId()

	/**
	 * A row's identifier, the flat `id` first, then the flat `uuid`, then
	 * `@self`'s id and uuid; null when it names none.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return string|null
	 *
	 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	public function firstIdOrUuid(array $row): ?string {
		$self = $this->selfOf(row: $row);
		return $this->firstOf(candidates: [($row['id'] ?? null), ($row['uuid'] ?? null), ($self['id'] ?? null), ($self['uuid'] ?? null)]);
	}//end firstIdOrUuid()

	/**
	 * The row's `@self` block, or none.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return array<string, mixed>
	 */
	private function selfOf(array $row): array {
		$self = ($row['@self'] ?? null);
		if (is_array($self) === true) {
			return $self;
		}

		return [];
	}//end selfOf()

	/**
	 * The first candidate that is a non-empty string or an integer, as a
	 * string; null when there is none.
	 *
	 * @param array<int, mixed> $candidates The candidates, in order.
	 *
	 * @return string|null
	 */
	private function firstOf(array $candidates): ?string {
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end firstOf()
}//end class
