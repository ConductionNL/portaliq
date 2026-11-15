<?php

/**
 * Portaliq Block Resolve Run
 *
 * One pass over a page's blocks: the references a block may resolve
 * against, shared by every step that resolves one block.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The references of one PortalBlockResolver::normaliseBlocks() call. Never
 * leaves that class.
 *
 * @internal
 *
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
 */
final class BlockResolveRun {
	/**
	 * Constructor.
	 *
	 * @param array<int, string>               $collectionIds The valid collection ids.
	 * @param array<int, string>               $actionIds     The valid action ids.
	 * @param array<int, array<string, mixed>> $collections   The sanitised collections.
	 * @param array<int, string>               $pageIds       The contribution's page ids.
	 */
	public function __construct(
		public readonly array $collectionIds,
		public readonly array $actionIds,
		public readonly array $collections,
		public readonly array $pageIds,
	) {
	}//end __construct()
}//end class
