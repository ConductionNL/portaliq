<?php

/**
 * Whether a resident may delete their own messages from an inbox collection
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel;

use OCA\Portaliq\Kernel\Contracts\PortalInboxReaderInterface;

/**
 * The inbox deletion rule, readable from every component.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
 */
final class InboxDeletion {

	/**
	 * Whether a resident may delete their own messages from an inbox
	 * collection: portaliq's own notices always, an app's message only where
	 * the app declares `deletable: true` (inbox-delete-own-messages). Who owns
	 * a row is checked separately, on the row itself, before any delete.
	 *
	 * @param array<string, mixed> $collection The inbox collection.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-can-delete-their-own-inbox-messages
	 */
	public static function residentMayDelete(array $collection): bool {
		$register = ($collection['register'] ?? '');
		$schema   = ($collection['schema'] ?? '');
		if ($register === PortalInboxReaderInterface::OWN_MESSAGES['register'] && $schema === PortalInboxReaderInterface::OWN_MESSAGES['schema']) {
			return true;
		}

		return ($collection['deletable'] ?? false) === true;
	}//end residentMayDelete()
}//end class
