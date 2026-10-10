<?php

/**
 * Portaliq Row Identifier (contribution-pay-screen)
 *
 * The identifier of a proven row.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Reads the identifier of a row that was read under the subject's scope.
 *
 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
 */
class RowIdentifier {
	/**
	 * The row's own identifier, else the path id it was read by.
	 *
	 * @param array<string, mixed> $row The proven row.
	 * @param string $fallback The path id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/contribution-pay-screen/specs/portal-contribution-contract/spec.md#requirement-a-row-scoped-forward-must-prove-the-row-before-it-forwards
	 */
	public function idFor(array $row, string $fallback): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([($row['id'] ?? null), ($row['uuid'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return $fallback;
	}//end idFor()
}//end class
