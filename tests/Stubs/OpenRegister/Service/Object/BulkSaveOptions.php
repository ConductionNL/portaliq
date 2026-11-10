<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's BulkSaveOptions on
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

/**
 * How one SaveObjects::saveObjects() call saves its rows, apart from its RBAC and multitenancy flags.
 *
 * No strict_types: SaveObjects declares none, and these values arrive from it.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class BulkSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param BulkBatchDefaults $defaults   The register and schema for every row that names none.
	 * @param bool              $validation Whether to validate rows against their schema.
	 * @param bool              $events     Whether to dispatch object lifecycle events.
	 * @param bool              $audit      Whether to write audit trail rows for the persisted objects.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(
		public readonly BulkBatchDefaults $defaults=new BulkBatchDefaults(),
		public readonly bool $validation=false,
		public readonly bool $events=false,
		public readonly bool $audit=true,
	) {
	}//end __construct()
}//end class
