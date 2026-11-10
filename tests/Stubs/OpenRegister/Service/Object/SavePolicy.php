<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's SavePolicy on
 * openregister #9123, loaded only when OpenRegister is absent (tests/bootstrap.php) and
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
 * The policy switches of one object save. Every default is the behaviour a caller gets without asking.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class SavePolicy {

	/**
	 * Constructor.
	 *
	 * @param bool $silent        Skip audit trail creation and events.
	 * @param bool $validation    Validate the object against its schema. A bulk importer with
	 *                            pre-validated rows may decline re-validating every one of them.
	 * @param bool $failIfExists  Insert-only: throw ObjectExistsException rather than update when the
	 *                            identifier is taken. False is an upsert.
	 * @param bool $dedupOverride Save through a blocking duplicate match, when the caller is entitled to.
	 *                            ObjectsController reads it from the raw request, because the body filter
	 *                            there strips `_`-prefixed keys.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Each flag is a documented save policy with a safe default.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(
		public readonly bool $silent=false,
		public readonly bool $validation=true,
		public readonly bool $failIfExists=false,
		public readonly bool $dedupOverride=false,
	) {
	}//end __construct()
}//end class
