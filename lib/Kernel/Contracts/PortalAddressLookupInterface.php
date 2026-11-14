<?php

/**
 * Contract of PortalAddressLookup
 *
 * The public surface of PortalAddressLookup that other components call, so they depend
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
 * Contract of PortalAddressLookup.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAddressLookupInterface {

	/**
	 * The street and town of an address, or null when none is found.
	 *
	 * @param string $postcode The postcode, the space optional.
	 * @param string $number   The house number, digits only.
	 * @param string $letter   The house letter, or ''.
	 * @param string $addition The house number addition, or ''.
	 *
	 * @return array{street: string, town: string}|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
	 */
	public function find(string $postcode, string $number, string $letter='', string $addition=''): ?array;
}//end interface
