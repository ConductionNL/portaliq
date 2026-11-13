<?php

/**
 * Contract of ContactAddressValues
 *
 * The public surface of ContactAddressValues that other components call, so they depend
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
 * Contract of ContactAddressValues.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactAddressValuesInterface {



	/**
	 * Whether the portal asks for an e-mail address after sign-in
	 * (REQ-IPP-005): the account has none in use, or dispatch flagged that it
	 * needs another way to reach the person.
	 *
	 * @param array<string, mixed>|null $account The account row, or null.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-are-asked-for-an-e-mail-address-when-there-is-none-req-ipp-005
	 */
	public function needsContactPrompt(?array $account): bool;
}//end interface
