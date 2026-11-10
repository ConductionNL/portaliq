<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's BulkBatchDefaults on
 * openregister #9108, loaded only when OpenRegister is absent (tests/bootstrap.php) and
 * read by psalm and phpstan. Only the @spec tags differ.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test stub of an OpenRegister class
 */

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The default register and schema of one bulk batch, as the caller passed them.
 *
 * No strict_types: SaveObjects declares none, and these values arrive from it.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class BulkBatchDefaults {

	/**
	 * Constructor.
	 *
	 * @param Register|string|int|null $register Default register context.
	 * @param Schema|string|int|null   $schema   Default schema context (null = mixed-schema).
	 */
	public function __construct(
		public readonly Register|string|int|null $register=null,
		public readonly Schema|string|int|null $schema=null,
	) {
	}//end __construct()
}//end class
