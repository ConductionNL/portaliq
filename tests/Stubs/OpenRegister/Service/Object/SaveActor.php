<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's SaveActor on
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

use OCP\IUser;

/**
 * On whose behalf one object save is written, and who owns a row it creates.
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class SaveActor {

	/**
	 * Constructor.
	 *
	 * @param IUser|null  $currentUser Explicit acting user for `@self.folder` access checks; null resolves
	 *                                 `IUserSession::getUser()`. Non-HTTP callers (cron, import pipelines,
	 *                                 event listeners) MUST pass one to avoid the default-deny fall-through
	 *                                 on every folder-bound save.
	 * @param string|null $owner       An explicit owner for a NEW object (a draft's scope value such as
	 *                                 `portal:<subjectRef>`, decision 195). Service-level only: no controller
	 *                                 forwards it. An update keeps the stored owner.
	 * @param bool        $unowned     Stamp the SYSTEM identity as owner even when a user session exists, for
	 *                                 a genuinely anonymous write (a citizen submitting a public form). No
	 *                                 controller forwards it, so no REST request can ask to be anonymised.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Attributed writes are the default.
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(
		public readonly ?IUser $currentUser=null,
		public readonly ?string $owner=null,
		public readonly bool $unowned=false,
	) {
	}//end __construct()
}//end class
