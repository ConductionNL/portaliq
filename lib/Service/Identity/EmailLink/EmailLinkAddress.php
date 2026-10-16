<?php

/**
 * Portaliq Email Link Address
 *
 * One address, one spelling: lower case and trimmed. What is counted, stored
 * and logged is the hash of that spelling, never the address itself
 * (security review M5, L3).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity\EmailLink
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#6
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

/**
 * Normalises, hashes and masks a sign-in address.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#6
 */
class EmailLinkAddress {
	/**
	 * The address as it is stored and compared: trimmed, lower case.
	 *
	 * @param string $address The address as typed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	public function normalise(string $address): string {
		return strtolower(trim($address));
	}//end normalise()

	/**
	 * The SHA-256 of the normalised address, or '' for an empty one.
	 *
	 * @param string $address The address as typed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-form-is-rate-limited-per-mailbox-per-client-and-per-portal-req-iwi-008
	 */
	public function hash(string $address): string {
		$normalised = $this->normalise(address: $address);
		if ($normalised === '') {
			return '';
		}

		return hash('sha256', $normalised);
	}//end hash()

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
	public function mask(string $address): string {
		$address = $this->normalise(address: $address);
		$atSign  = strrpos($address, '@');
		if ($atSign === false || $atSign === 0) {
			return '***';
		}

		return substr($address, 0, 1) . '***' . substr($address, $atSign);
	}//end mask()
}//end class
