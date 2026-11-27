<?php

/**
 * OpenRegister test stub: the request ObjectTreeService::tree() takes.
 *
 * Mirrors the constructor of openregister's class on `main` (parameter names,
 * order and defaults), loaded by tests/bootstrap-unit.php only when
 * OpenRegister is absent. The types of the entity parameters are widened to
 * `object` because OpenRegister's entity and contract classes are absent too.
 *
 * @category Tests
 * @package  OCA\\OpenRegister\\Service\\Relation
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

namespace OCA\OpenRegister\Service\Relation;

/**
 * One tree read: the root, the direction, how deep and which fields.
 */
final class ObjectTreeQuery {

	/**
	 * Constructor.
	 *
	 * @param object   $root      The root object entity.
	 * @param string   $direction 'descendants' or 'ancestors'.
	 * @param int|null $depth     How deep to read.
	 * @param string[] $fields    The fields each node carries.
	 */
	public function __construct(
		public readonly object $root,
		public readonly string $direction = 'descendants',
		public readonly ?int $depth = null,
		public readonly array $fields = [],
	) {
	}//end __construct()
}//end class
