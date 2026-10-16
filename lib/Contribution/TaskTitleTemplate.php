<?php

/**
 * Portaliq Task Title Template (lookup-by-row-field)
 *
 * A task may be titled by a sentence with fields in it: "Kies een tijd voor
 * het oudergesprek van {childName}", where `childName` comes from a lookup on
 * the row's `learnerRef`. The sentence is kept only when it is short and every
 * `{name}` is a field the rows carry, a looked-up value included.
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
 * @spec openspec/changes/lookup-by-row-field/specs/portal-contribution-contract/spec.md#requirement-a-task-may-be-titled-by-a-sentence-with-fields
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Sanitises a tasks block's `titleTemplate`.
 *
 * @spec openspec/changes/lookup-by-row-field/specs/portal-contribution-contract/spec.md#requirement-a-task-may-be-titled-by-a-sentence-with-fields
 */
class TaskTitleTemplate {
	/**
	 * The longest sentence kept.
	 */
	private const MAX_LENGTH = 200;

	/**
	 * The block's `titleTemplate`, or [] when it does not fit.
	 *
	 * @param mixed                $declared   The declared template.
	 * @param array<string, mixed> $collection The collection, with its lookup names among its fields.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/lookup-by-row-field/specs/portal-contribution-contract/spec.md#requirement-a-task-may-be-titled-by-a-sentence-with-fields
	 */
	public function keys(mixed $declared, array $collection): array {
		if (is_string($declared) === false || trim($declared) === '' || mb_strlen($declared) > self::MAX_LENGTH) {
			return [];
		}

		preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $declared, $names);
		if ($names[1] === []) {
			return [];
		}

		$fields = ($collection['fields'] ?? null);
		foreach ($names[1] as $name) {
			if (is_array($fields) === true && in_array($name, $fields, true) === false) {
				return [];
			}
		}

		return ['titleTemplate' => trim($declared)];
	}//end keys()
}//end class
