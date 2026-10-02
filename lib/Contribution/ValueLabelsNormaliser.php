<?php

/**
 * Portaliq Value Labels Normaliser (contribution-value-labels)
 *
 * An app MAY say how a stored value reads: `valueLabels` maps a raw value
 * ("approved", "illness") to the words a resident reads ("Goedgekeurd",
 * "Ziekte"). The same map shape serves a collection column, where the cell
 * shows the label, and an action field config, where an enum select shows the
 * label and still submits the raw value.
 *
 * Fail-closed: only string labels on string or integer keys survive, both
 * length-capped, and the whole key goes when nothing usable is left. A label
 * is presentation only; it never changes which values a field accepts.
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
 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a well-formed `valueLabels` map, or nothing.
 *
 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
 */
class ValueLabelsNormaliser {
	/**
	 * The manifest key, on a column and on a field config.
	 */
	public const KEY = 'valueLabels';

	/**
	 * The most labels one field keeps.
	 */
	private const MAX_ENTRIES = 100;

	/**
	 * The longest value a label may be declared for.
	 */
	private const MAX_VALUE_LENGTH = 100;

	/**
	 * The longest label kept.
	 */
	private const MAX_LABEL_LENGTH = 200;

	/**
	 * Copy a well-formed `valueLabels` from a declared entry onto a sanitised one.
	 *
	 * @param array<string, mixed> $entry  The sanitised entry built so far.
	 * @param array<mixed>         $source The declared entry.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
	 */
	public function apply(array $entry, array $source): array {
		$labels = $this->normalise(value: ($source[self::KEY] ?? null));
		if ($labels !== []) {
			$entry[self::KEY] = $labels;
		}

		return $entry;
	}//end apply()

	/**
	 * The usable labels of a declared map: string or integer keys, non-empty
	 * string labels, both within their length cap.
	 *
	 * @param mixed $value The declared map.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
	 */
	public function normalise(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$labels = [];
		foreach ($value as $raw => $label) {
			$key = (string) $raw;
			if ($key === '' || mb_strlen($key) > self::MAX_VALUE_LENGTH) {
				continue;
			}

			if (is_string($label) === false || trim($label) === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
				continue;
			}

			$labels[$key] = $label;
			if (count($labels) === self::MAX_ENTRIES) {
				break;
			}
		}

		return $labels;
	}//end normalise()
}//end class
