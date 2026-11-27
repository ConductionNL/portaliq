<?php

/**
 * Contract of ThemeChoice
 *
 * The public surface of ThemeChoice that other components call, so they depend
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
 * Contract of ThemeChoice.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ThemeChoiceInterface {

	/**
	 * Drop the tags the portal does not declare and announce the themes with content.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregate.
	 * @param mixed $themes The portal's `themes`.
	 *
	 * @return array<string, mixed> The aggregate; `themes` lists the declared themes that have a collection or action tagged for this resident.
	 *
	 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t02
	 */
	public function arrange(array $aggregate, mixed $themes): array;
}//end interface
