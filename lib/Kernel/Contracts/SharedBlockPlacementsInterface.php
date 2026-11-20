<?php

/**
 * Contract of SharedBlockPlacements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of SharedBlockPlacements.
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SharedBlockPlacementsInterface {

	/**
	 * The pages of the block's organisation whose published layout places the block.
	 *
	 * @param string $blockId The shared block's id or uuid.
	 *
	 * @return array<int, array{id: string, title: string, route: string, status: string, portal: string, portalTitle: string}>|null
	 *   The pages, ordered by portal and route; null when the signed-in user cannot read the block.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t06
	 */
	public function pagesOf(string $blockId): ?array;
}//end interface
