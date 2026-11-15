<?php

/**
 * OpenRegister signature double: the one ObjectEntity method the portal writer reads,
 * `jsonSerialize()`, with its signature copied from openregister's class on
 * `development` (3f804c2fda). A test doubles it with
 * `onlyMethods(['jsonSerialize'])` when OpenRegister is not loaded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\Portaliq\Tests\Doubles
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Doubles;

use JsonSerializable;

/**
 * Signature stand-in for OpenRegister's ObjectEntity.
 */
class OpenRegisterObjectEntity implements JsonSerializable {
	/**
	 * The object as an array.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [];
	}//end jsonSerialize()
}//end class
