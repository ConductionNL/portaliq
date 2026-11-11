<?php

/**
 * Portaliq Account Provision Request
 *
 * The account someone asks to provision before any login: the audience and
 * tenant it belongs to, the identity and address it can later be matched
 * on, the name to greet the person by and who asked.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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

namespace OCA\Portaliq\Service;

/**
 * An account to provision before any login (REQ-PIS-001).
 *
 * @spec openspec/specs/portal-identity-space/spec.md#requirement-an-account-can-be-provisioned-before-any-login-req-pis-001
 */
final class AccountProvisionRequest {
	/**
	 * Constructor.
	 *
	 * @param string $audience      The external audience ("client"|"supplier"|...).
	 * @param string $organisation  The tenant slug.
	 * @param bool   $verifiedEmail True when the address was verified out of
	 *                              band. A field OF the account, carried to the
	 *                              stored row; nothing branches on it.
	 * @param string $identityType  One of the register's identityType enum, or ''.
	 * @param string $identityRef   The identity reference, or '' when unknown.
	 * @param string $email         A contact address, or '' when none is known.
	 * @param string $provisionedBy The staff user id or app id that asked.
	 * @param string $displayName   The name to greet the person by, or ''.
	 */
	public function __construct(
		public readonly string $audience,
		public readonly string $organisation,
		public readonly bool $verifiedEmail,
		public readonly string $identityType = '',
		public readonly string $identityRef = '',
		public readonly string $email = '',
		public readonly string $provisionedBy = '',
		public readonly string $displayName = '',
	) {
	}//end __construct()
}//end class
