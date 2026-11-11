<?php

/**
 * Portaliq Account Login Run
 *
 * The state of one find-or-create while `PortalAccountService` walks it: the
 * identity the sign-in carried, the tenant, the audience the sign-in route
 * proposes and the address the broker says it verified. It never leaves the
 * service that builds it.
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
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One find-or-create in progress.
 *
 * @spec openspec/specs/portal-identity-space/spec.md#requirement-first-login-matches-the-pending-account-req-pis-002
 */
final class AccountLoginRun {

	/**
	 * The tenant slug.
	 *
	 * @var string
	 */
	public string $organisation = '';

	/**
	 * The audience the sign-in route proposes.
	 *
	 * @var string
	 */
	public string $audience = '';

	/**
	 * An address the broker says it verified, or ''.
	 *
	 * @var string
	 */
	public string $verifiedEmail = '';

	/**
	 * Constructor.
	 *
	 * @param string $identityType The identity type the sign-in carried.
	 * @param string $identityRef  The identity reference the sign-in carried.
	 */
	public function __construct(
		public readonly string $identityType,
		public readonly string $identityRef,
	) {
	}//end __construct()

	/**
	 * Whether the sign-in names a complete identity: a type, a reference and
	 * a tenant.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md#requirement-first-login-matches-the-pending-account-req-pis-002
	 */
	public function isComplete(): bool {
		return $this->identityType !== '' && $this->identityRef !== '' && $this->organisation !== '';
	}//end isComplete()
}//end class
