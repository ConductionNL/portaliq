<?php

/**
 * The arguments of a saveObject() call by the names they had before
 * openregister moved register, schema and uuid into ObjectSaveOptions.
 *
 * Test doubles written against the old signature record or branch on
 * `register`, `schema` and `uuid`; this reads those out of the options so a
 * double keeps asserting the same things.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Tests
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

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Reads a saveObject() call as object, register, schema, uuid and the two flags.
 */
final class SaveCallArgs {

	/**
	 * The call's arguments by their old names.
	 *
	 * @param array<int|string, mixed> $args The arguments the double received, named or positional:
	 *                                       object, options, _rbac, _multitenancy.
	 *
	 * @return array{object: mixed, register: mixed, schema: mixed, uuid: ?string, _rbac: bool, _multitenancy: bool}
	 */
	public static function named(array $args): array {
		$options = ($args['options'] ?? $args[1] ?? null);
		if ($options instanceof ObjectSaveOptions === false) {
			$options = new ObjectSaveOptions();
		}

		return [
			'object' => ($args['object'] ?? $args[0] ?? null),
			'register' => $options->target->register,
			'schema' => $options->target->schema,
			'uuid' => $options->uuid,
			'_rbac' => (bool)($args['_rbac'] ?? $args[2] ?? true),
			'_multitenancy' => (bool)($args['_multitenancy'] ?? $args[3] ?? true),
		];
	}//end named()
}//end class
