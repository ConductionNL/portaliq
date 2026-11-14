<?php

/**
 * Contract of EmailLinkAddress
 *
 * The public surface of EmailLinkAddress that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of EmailLinkAddress.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailLinkAddressInterface {

	/**
	 * The SHA-256 of the normalised address, or '' for an empty one.
	 *
	 * @param string $address The address as typed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	public function hash(string $address): string;

	/**
	 * The address with all but its first letter and its domain hidden, for the
	 * link page (security review L2): `t***@example.nl`.
	 *
	 * @param string $address The address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-link-opened-in-another-browser-asks-for-the-address-first-req-iwi-010
	 */
	public function mask(string $address): string;

	/**
	 * The address as it is stored and compared: trimmed, lower case.
	 *
	 * @param string $address The address as typed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	public function normalise(string $address): string;
}//end interface
