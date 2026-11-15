<?php

/**
 * OpenRegister test stub: the options ObjectService::find() takes.
 *
 * Mirrors the constructor of openregister's class on `main` (parameter names,
 * order and defaults), loaded by tests/bootstrap-unit.php only when
 * OpenRegister is absent. The types of the entity parameters are widened to
 * `object` because OpenRegister's entity and contract classes are absent too.
 *
 * @category Tests
 * @package  OCA\\OpenRegister\\Kernel\\Contracts\\Value
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Value;

/**
 * Where to find an object and how to return it.
 */
final class ObjectFindOptions {

	/**
	 * Constructor.
	 *
	 * @param object|string|int|null $register The register.
	 * @param object|string|int|null $schema   The schema.
	 * @param array|null             $extend   Properties to extend.
	 * @param bool                   $files    Whether to include files.
	 * @param bool                   $render   Whether to render the object.
	 * @param bool                   $audit    Whether to write an audit trail entry.
	 */
	public function __construct(
		public readonly object|string|int|null $register=null,
		public readonly object|string|int|null $schema=null,
		public readonly ?array $extend=[],
		public readonly bool $files=false,
		public readonly bool $render=true,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
