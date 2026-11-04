<?php

/**
 * Row As Array
 *
 * Reads a stored OpenRegister row as an array.
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
 * A stored row as an array: an array as it is, an entity cast from what it
 * serialises to, anything else empty.
 *
 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
 */
trait RowAsArray {
	/**
	 * A stored row as an array.
	 *
	 * @param mixed $row The row, an array or an entity.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end asArray()
}//end trait
