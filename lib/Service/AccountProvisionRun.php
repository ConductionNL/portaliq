<?php

/**
 * Portaliq Account Provision Run
 *
 * The state of one provisioning while `PortalAccountService` walks it: the
 * audience and tenant, then the identity, address, name and who asked. It
 * never leaves the service that builds it.
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
 * One provisioning in progress.
 *
 * @spec openspec/specs/portal-identity-space/spec.md#requirement-an-account-can-be-provisioned-before-any-login-req-pis-001
 */
final class AccountProvisionRun {

	/**
	 * One of the register's identityType enum, or ''.
	 *
	 * @var string
	 */
	public string $identityType = '';

	/**
	 * The identity reference, or ''.
	 *
	 * @var string
	 */
	public string $identityRef = '';

	/**
	 * A contact address, or ''.
	 *
	 * @var string
	 */
	public string $email = '';

	/**
	 * Whether that address was verified out of band.
	 *
	 * @var bool
	 */
	public bool $verifiedEmail = false;

	/**
	 * The staff user id or app id that asked.
	 *
	 * @var string
	 */
	public string $provisionedBy = '';

	/**
	 * The name to greet the person by, or ''.
	 *
	 * @var string
	 */
	public string $displayName = '';

	/**
	 * Constructor.
	 *
	 * @param string $audience     The external audience.
	 * @param string $organisation The tenant slug.
	 */
	public function __construct(
		public readonly string $audience,
		public readonly string $organisation,
	) {
	}//end __construct()

	/**
	 * Whether the call names an identity: a type and a reference.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md#requirement-an-account-can-be-provisioned-before-any-login-req-pis-001
	 */
	public function hasIdentity(): bool {
		return $this->identityType !== '' && $this->identityRef !== '';
	}//end hasIdentity()

	/**
	 * Whether the call can be provisioned: an audience and a tenant, and an
	 * identity or an address. A row with neither could never be matched by
	 * anything and would only be a dangling subjectRef.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md#requirement-an-account-can-be-provisioned-before-any-login-req-pis-001
	 */
	public function isProvisionable(): bool {
		return in_array('', [$this->audience, $this->organisation], true) === false
			&& ($this->hasIdentity() === true || $this->email !== '');
	}//end isProvisionable()

	/**
	 * The identity fields of the row: none for an address-only account.
	 *
	 * An address-only account (a self-registration, an invitation) has no
	 * identity yet, and `identityType` is an enum: an empty string is no
	 * member of it, so the register would refuse the row. The two fields
	 * are left out until the sign-in that matches the account fills them.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md#requirement-an-account-can-be-provisioned-before-any-login-req-pis-001
	 */
	public function identityFields(): array {
		if ($this->hasIdentity() === false) {
			return [];
		}

		return ['identityType' => $this->identityType, 'identityRef' => $this->identityRef];
	}//end identityFields()
}//end class
