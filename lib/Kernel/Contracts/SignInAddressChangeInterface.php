<?php

/**
 * Contract of SignInAddressChange
 *
 * The public surface of SignInAddressChange that other components call, so they depend
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
 * Contract of SignInAddressChange.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SignInAddressChangeInterface {

	/**
	 * The address changed.
	 */
	public const CHANGED = 'changed';

	/**
	 * The store did not take the write.
	 */
	public const FAILED = 'failed';

	/**
	 * Change the sign-in address of an `email` account.
	 *
	 * @param array<string, mixed> $account    The account row.
	 * @param string               $newAddress The new address.
	 * @param bool                 $byStaff    Whether staff make the change.
	 * @param string               $trust      The session's trust when the account holder makes it.
	 *
	 * @return string One of the constants.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-an-e-mail-link-session-is-a-fresh-low-session-that-cannot-raise-itself-req-iwi-011
	 */
	public function change(array $account, string $newAddress, bool $byStaff, string $trust = ''): string;
}//end interface
