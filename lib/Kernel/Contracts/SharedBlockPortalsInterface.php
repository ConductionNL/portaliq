<?php

/**
 * Contract of SharedBlockPortals
 *
 * The public surface of SharedBlockPortals that other components call, so they depend
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
 * Contract of SharedBlockPortals.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SharedBlockPortalsInterface {

	/**
	 * Whether the written data is a shared block: an organisation, no portal, and a widget grid.
	 *
	 * @param array<string, mixed> $data The written object's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
	 */
	public function isBlock(array $data): bool;

	/**
	 * The slugs of the portals of the block's organisation.
	 *
	 * @param array<string, mixed>             $data    The written block's data.
	 * @param array<int, array<string, mixed>> $portals The published portals.
	 *
	 * @return string[] The slugs, once each; none when the data is not a block.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
	 */
	public function slugsFor(array $data, array $portals): array;
}//end interface
