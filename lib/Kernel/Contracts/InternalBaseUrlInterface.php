<?php

/**
 * Contract of InternalBaseUrl
 *
 * The public surface of InternalBaseUrl that other components call, so they depend
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
 * Contract of InternalBaseUrl.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InternalBaseUrlInterface {

	/**
	 * The app config key the address is stored under.
	 */
	public const CONFIG_KEY = 'internal_base_url';

	/**
	 * Store an address after validation. An empty value clears it.
	 *
	 * @param string $value The address the administrator entered.
	 *
	 * @return bool False when the address was refused and nothing was stored.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function store(string $value): bool;

	/**
	 * The stored value as it is, valid or not. For the admin form.
	 *
	 * @return string
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function stored(): string;
}//end interface
