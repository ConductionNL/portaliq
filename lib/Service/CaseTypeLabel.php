<?php

/**
 * Case Type Label
 *
 * Reads the name of a case type.
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
 * @spec exclude Shared label helper; the @spec of each calling method covers the name it shows.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The name of a case type. The using class carries `LABEL_FIELDS`, the
 * fields read in order when the source names none.
 *
 * @spec exclude Shared label helper; the @spec of each calling method covers the name it shows.
 */
trait CaseTypeLabel {
	/**
	 * A case type's name: the source's label field first, then each of
	 * `LABEL_FIELDS`; the first non-blank string, trimmed, or ''.
	 *
	 * @param array<string, mixed> $type       The case type.
	 * @param string               $labelField The field the source names, or ''.
	 *
	 * @return string
	 *
	 * @spec exclude Shared label helper; the @spec of each calling method covers the name it shows.
	 */
	private function labelOf(array $type, string $labelField): string {
		$fields = self::LABEL_FIELDS;
		if ($labelField !== '') {
			array_unshift($fields, $labelField);
		}

		foreach ($fields as $field) {
			$value = ($type[$field] ?? null);
			if (is_string($value) === true && trim($value) !== '') {
				return trim($value);
			}
		}

		return '';
	}//end labelOf()
}//end trait
