<?php

/**
 * Contract of MediaLibraryReader
 *
 * The public surface of MediaLibraryReader that other components call, so they depend
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
 * Contract of MediaLibraryReader.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MediaLibraryReaderInterface {

	/**
	 * The routes of the published pages of a portal that use an item, as hero
	 * image, share image or anywhere in the body.
	 *
	 * Uncached: it guards a delete, and a stale answer there removes an image
	 * from a live page.
	 *
	 * @param string $portal The portal slug.
	 * @param string $id     The item id.
	 *
	 * @return list<string> The routes, sorted.
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function pagesUsing(string $portal, string $id): array;
}//end interface
