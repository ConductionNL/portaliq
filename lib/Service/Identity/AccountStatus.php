<?php

/**
 * Portaliq Account Status
 *
 * The status and provenance values a `portalAccount` row is stored and
 * compared with. Split out of PortalAccountService, so a class that only
 * reads an account's status no longer depends on the service that finds,
 * creates and provisions accounts.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * The stored values of a `portalAccount` row's `status` and `provisionedBy`.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
final class AccountStatus {
	/**
	 * An account provisioned before any login: no session, no reach.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public const PENDING = 'pending';

	/**
	 * An account a matching first login has activated.
	 */
	public const ACTIVE = 'active';

	/**
	 * A pending account a clerk withdrew. It never matches a login again.
	 */
	public const VOID = 'void';

	/**
	 * The `provisionedBy` of an account a stranger created through self-registration.
	 */
	public const SELF_REGISTRATION = 'self-registration';
}//end class
