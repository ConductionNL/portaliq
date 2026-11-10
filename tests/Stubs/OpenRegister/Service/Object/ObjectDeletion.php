<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's ObjectDeletion on
 * openregister #9099, loaded only when OpenRegister is absent (tests/bootstrap.php) and
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

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCP\IUser;

/**
 * One delete a caller asks for: the object, where to find it, who acts and whether the row is destroyed.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class ObjectDeletion {

	/**
	 * Constructor.
	 *
	 * @param string           $uuid        The UUID of the object to delete.
	 * @param ObjectFindTarget $target      The register and schema the caller named. When both are
	 *                                      set the lookup targets exactly that magic table.
	 * @param IUser|null       $currentUser Explicit acting user for the RBAC `delete` check, or null
	 *                                      for the session user. A sessionless caller (flow run, cron,
	 *                                      import pipeline) must set one: anonymous is default-deny.
	 * @param bool             $permanent   Destroy the row rather than tombstone it, so its identifier
	 *                                      becomes reusable. Reserve it for rows that are a claim rather
	 *                                      than a record (openregister#2459).
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Soft delete is the default every caller gets.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(
		public readonly string $uuid,
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?IUser $currentUser=null,
		public readonly bool $permanent=false,
	) {
	}//end __construct()

	/**
	 * Whether the caller named both a register and a schema, so the lookup is scoped to one table.
	 *
	 * @return bool
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function isScoped(): bool {
		return $this->target->register !== null && $this->target->schema !== null;
	}//end isScoped()
}//end class
