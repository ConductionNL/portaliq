<?php

/**
 * OpenRegister test stub: the one ObjectEntity method the portal writer reads,
 * `jsonSerialize()`, with its signature copied from openregister's class on
 * `development` (3f804c2fda). Loaded only when OpenRegister is absent. A test
 * doubles it with `onlyMethods(['jsonSerialize'])`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use JsonSerializable;

/**
 * Signature stand-in for OpenRegister's ObjectEntity.
 */
class ObjectEntity implements JsonSerializable {
	/**
	 * The object as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [];
	}//end jsonSerialize()
}//end class
