<?php

/**
 * Contract of TimelineProviderMethod
 *
 * The public surface of TimelineProviderMethod that other components call, so they depend
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
 * Contract of TimelineProviderMethod.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TimelineProviderMethodInterface {

	/**
	 * Whether a declared name is one portaliq may call.
	 *
	 * @param mixed $name The declared `timeline.provider`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function accepts(mixed $name): bool;

	/**
	 * Whether a provider has a public instance method of that name that
	 * portaliq may call with one id and nothing else. The message box
	 * recipient method (inbox-berichtenbox-channel) is held to the same rule
	 * as a timeline method.
	 *
	 * @param object $provider The contributing app's provider.
	 * @param string $method   The declared name.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md
	 */
	public function callableOn(object $provider, string $method): bool;
}//end interface
