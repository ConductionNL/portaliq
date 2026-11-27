<?php

/**
 * Row Id Of
 *
 * Reads the id OpenRegister gave a row, wherever it put it.
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
 * The first non-empty string id of a row: `@self.id`, `@self.uuid`, `id`,
 * then `uuid`.
 *
 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
 */
trait RowIdOf {
	/**
	 * The id OpenRegister gave a row, wherever it put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or '' when the row names none.
	 *
	 * @spec exclude Shared row helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	public function idOf(array $row): string {
		$self = [];
		if (is_array($row['@self'] ?? null) === true) {
			$self = $row['@self'];
		}

		foreach ([($self['id'] ?? null), ($self['uuid'] ?? null), ($row['id'] ?? null), ($row['uuid'] ?? null)] as $id) {
			if (is_string($id) === true && $id !== '') {
				return $id;
			}
		}

		return '';
	}//end idOf()
}//end trait
