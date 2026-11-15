<?php

/**
 * Contract of MediaFile
 *
 * The public surface of MediaFile that other components call, so they depend
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

use OCP\AppFramework\Http\StreamResponse;

/**
 * Contract of MediaFile.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MediaFileInterface {

	/**
	 * The item's file, or null when there is nothing to serve.
	 *
	 * @param array<string, mixed> $portal The resolved serving portal.
	 * @param string               $id     The item id.
	 *
	 * @return StreamResponse|null
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function stream(array $portal, string $id): ?StreamResponse;
}//end interface
