<?php

/**
 * Contract of ExampleSiteInstaller
 *
 * The public surface of ExampleSiteInstaller that other components call, so they depend
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
 * Contract of ExampleSiteInstaller.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExampleSiteInstallerInterface {

	/**
	 * Write what the declaration holds and the instance lacks, then prove it.
	 *
	 * @param array<string, mixed> $site A declaration from ExampleSiteCatalogue.
	 *
	 * @return array<string, mixed> `site`, `portal`, `available`, `themeOffered`,
	 *                              `types` (per schema: declared, created, kept,
	 *                              arrived), `missing`, `lost` and `ok`.
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function install(array $site): array;
}//end interface
