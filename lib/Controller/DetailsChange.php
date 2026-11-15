<?php

/**
 * Portaliq Details Change
 *
 * One change a signed-in resident makes to their own account details: whose
 * account, and what changes.
 *
 * @category Controller
 * @package  OCA\Portaliq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

/**
 * What one PortalAccountSelfController::updateDetails() call changes, for the
 * session's own subject.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T01
 */
final class DetailsChange {
	/**
	 * Constructor.
	 *
	 * @param string      $subjectRef         The session's subject.
	 * @param string      $organisation       The session's organisation.
	 * @param string      $displayName        The new name, or '' to leave it.
	 * @param string      $email              The new address, or '' to leave it.
	 * @param bool|null   $emailNotifications The email channel choice, or null to leave it.
	 * @param string|null $messageLanguage    The message language, or null to leave it.
	 */
	public function __construct(
		public readonly string $subjectRef,
		public readonly string $organisation,
		public readonly string $displayName,
		public readonly string $email,
		public readonly ?bool $emailNotifications,
		public readonly ?string $messageLanguage,
	) {
	}//end __construct()
}//end class
