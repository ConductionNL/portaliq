<?php

/**
 * Contract of PortalContactAddressService
 *
 * The public surface of PortalContactAddressService that other components call, so they depend
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
 * Contract of PortalContactAddressService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalContactAddressServiceInterface {

	/**
	 * Add an e-mail address or phone number.
	 *
	 * A new e-mail address is parked with a one-time secret, returned for the
	 * mail; asking again for an address that waits for confirmation mints a
	 * fresh secret, and the earlier link stops working.
	 *
	 * @param string $subjectRef The account.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address as typed.
	 *
	 * @return array{refusal: string, value: string, confirmationToken: string}
	 *         `refusal` '' on success, else `no_account`, `invalid`, `exists`,
	 *         `too_many_pending` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function addAddress(string $subjectRef, string $kind, string $value): array;

	/**
	 * Choose how the organisation contacts the person. A change is announced
	 * once as PortalContactDetailsChangedEvent; saving the same channel again
	 * announces nothing.
	 *
	 * @param string $subjectRef The account.
	 * @param string $channel `portal`, `email`, `phone` or `post`.
	 *
	 * @return string '' on success, else `no_account`, `invalid` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T04
	 */
	public function chooseChannel(string $subjectRef, string $channel): string;

	/**
	 * Mark an address as the preferred one of its kind. A preferred e-mail
	 * address becomes the account's `email`, the address notifications go to.
	 *
	 * @param string $subjectRef The account.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return string '' on success, else `no_account`, `not_found`,
	 *                `confirm_first` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function preferAddress(string $subjectRef, string $kind, string $value): string;

	/**
	 * Remove an address.
	 *
	 * @param string $subjectRef The account.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return string '' on success, else `no_account`, `not_found`,
	 *                `choose_another_preferred` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function removeAddress(string $subjectRef, string $kind, string $value): string;
}//end interface
