<?php

/**
 * Object Row Normaliser
 *
 * Turns one OpenRegister result, an array or an entity that serialises to
 * JSON, into a plain array.
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
 * One OpenRegister result as an array, or null when it is neither an array
 * nor an object that serialises to one.
 *
 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
 */
trait ObjectRowNormaliser {

	/**
	 * Normalise an OpenRegister result (array or entity) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	private function normalise(mixed $row): ?array {
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$row = $row->jsonSerialize();
		}

		if (is_array($row) === false) {
			return null;
		}

		return $row;
	}//end normalise()
}//end trait
