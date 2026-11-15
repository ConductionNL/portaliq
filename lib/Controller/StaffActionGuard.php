<?php

/**
 * Portaliq Staff Action Guard
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
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */

declare(strict_types=1);

namespace OCA\Portaliq\Controller;

use OCP\AppFramework\OCS\OCSForbiddenException;

/**
 * The signed-in staff member, once the controller's action is confirmed for them.
 *
 * The using controller provides `$userSession`, `$actionAuth` (ActionAuthService) and the `ACTION` it requires.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-an-event-is-authored-per-school-group-or-child-with-guardian-rsvp
 */
trait StaffActionGuard {
	/**
	 * The staff authorization guard every `#[NoAdminRequired]` method calls
	 * FIRST, before any read or write: `#[NoAdminRequired]` already opens
	 * the endpoint to every authenticated Nextcloud user, so this makes the
	 * requirement explicit at the call site (ADR-005) rather than relying
	 * only on the framework attribute, and narrows it to the groups holding
	 * the controller's ADR-023 action.
	 *
	 * @return string The staff member's user id.
	 *
	 * @throws OCSForbiddenException When no Nextcloud user is authenticated, or the
	 *                               user's groups do not hold self::ACTION.
	 */
	private function requireAuthenticatedStaff(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		$this->actionAuth->requireAction(user: $user, action: self::ACTION);

		return $user->getUID();
	}//end requireAuthenticatedStaff()
}//end trait
