<?php

/**
 * Portaliq Report Row Id
 *
 * The identifier of a report, reporter contact or reveal row as the store
 * hands it back: its own `uuid` or `id`, or those under `@self`.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Reports
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
 * @spec openspec/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

/**
 * Reads a row's own identifier.
 *
 * @spec openspec/specs/report-without-an-account/spec.md
 */
final class ReportRowId {
	/**
	 * A row's own identifier: the first non-empty string or integer of its
	 * `uuid`, `id`, `@self.uuid` and `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The identifier, '' when it has none.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public static function of(array $row): string {
		$self = (array)($row['@self'] ?? []);
		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end of()
}//end class
