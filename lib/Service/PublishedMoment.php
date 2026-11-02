<?php

/**
 * Published Moment
 *
 * Reads when a news item was published.
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
 * @spec exclude Shared row helper; the @spec of each calling method covers the order it sorts by.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The publication moment of a news row, for newest-first ordering.
 *
 * @spec exclude Shared row helper; the @spec of each calling method covers the order it sorts by.
 */
trait PublishedMoment {
	/**
	 * When an item was published, as a Unix timestamp; PHP_INT_MIN when undated.
	 * `publishedAt` first, else OpenRegister's `@self.published`, else
	 * `@self.created`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return int
	 *
	 * @spec exclude Shared row helper; the @spec of each calling method covers the order it sorts by.
	 */
	private function momentOf(array $row): int {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		foreach ([($row['publishedAt'] ?? null), ($self['published'] ?? null), ($self['created'] ?? null)] as $value) {
			if (is_string($value) === false || $value === '') {
				continue;
			}

			$time = strtotime($value);
			if ($time !== false) {
				return $time;
			}
		}

		return PHP_INT_MIN;
	}//end momentOf()
}//end trait
