<?php

/**
 * Contract of ExampleSiteRemover
 *
 * The public surface of ExampleSiteRemover that other components call, so they depend
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
 * Contract of ExampleSiteRemover.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExampleSiteRemoverInterface {

	/**
	 * Delete what the install recorded, and nothing else.
	 *
	 * @param string $site The site's id.
	 * @param string $slug The slug of its portal.
	 *
	 * @return array<string, mixed> `recorded` (false when there is no record),
	 *                              `available`, `deleted` and `gone` per schema,
	 *                              `failed` ids, and `portal`: `deleted`,
	 *                              `kept-content`, `kept-not-ours` or `failed`.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-site
	 */
	public function remove(string $site, string $slug): array;
}//end interface
