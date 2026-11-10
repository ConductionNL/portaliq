<?php

/**
 * Non Empty Strings
 *
 * Coerces a stored value to a list of its non-empty strings.
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
 * @spec exclude Shared value helper; the @spec of each calling method covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * A list of non-empty strings.
 *
 * @spec exclude Shared value helper; the @spec of each calling method covers the behaviour it reads for.
 */
final class NonEmptyStrings {
	/**
	 * Coerce a value to a list of strings, dropping anything else.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return array<int, string>
	 *
	 * @spec exclude Shared value helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	public static function of(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$list = [];
		foreach ($value as $item) {
			if (is_string($item) === true && $item !== '') {
				$list[] = $item;
			}
		}

		return $list;
	}//end of()
}//end class
