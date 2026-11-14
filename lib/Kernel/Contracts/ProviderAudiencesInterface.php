<?php

/**
 * Contract of ProviderAudiences
 *
 * The public surface of ProviderAudiences that other components call, so they depend
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
 * Contract of ProviderAudiences.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProviderAudiencesInterface {

	/**
	 * The full set of audiences a provider serves (contract v2, A2 duck
	 * typing — same preference order as `serves()`): `getAudiences()`
	 * when present, else the single `getAudience()` value, else none.
	 *
	 * @param object $provider The resolved provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/archive/2026-09-07-contract-v2/tasks.md#T2
	 */
	public function served(object $provider): array;
}//end interface
