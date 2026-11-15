<?php

/**
 * Portaliq Address Invitation
 *
 * One invitation a clerk sends: the address, the tenant inviting, the audience
 * the account will carry, the clerk, and how long the invitation lives.
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
 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * An address a clerk invites into the portal.
 *
 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
 */
final class AddressInvitation {
	/**
	 * Constructor.
	 *
	 * @param string $email        The address invited.
	 * @param string $organisation The tenant inviting.
	 * @param string $audience     The audience the account will carry, '' for client.
	 * @param string $invitedBy    The staff user sending it.
	 * @param string $ttl          An ISO 8601 duration, or '' for the default week.
	 */
	public function __construct(
		public readonly string $email,
		public readonly string $organisation,
		public readonly string $audience,
		public readonly string $invitedBy,
		public readonly string $ttl = '',
	) {
	}//end __construct()
}//end class
