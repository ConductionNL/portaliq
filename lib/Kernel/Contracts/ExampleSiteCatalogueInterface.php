<?php

/**
 * Contract of ExampleSiteCatalogue
 *
 * The public surface of ExampleSiteCatalogue that other components call, so they depend
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
 * Contract of ExampleSiteCatalogue.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExampleSiteCatalogueInterface {

	/**
	 * One declaration, or null when no usable one carries that id.
	 *
	 * A declaration is usable when it parses, names itself by its file name,
	 * holds a portal with a slug and a title, and each of its lists is a list.
	 * An id is at most 40 characters: the install keeps its record under an
	 * app-config key that starts with `example_site_`, and such a key holds 64.
	 *
	 * @param string $id The site's id, as its file is named.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function find(string $id): ?array;

	/**
	 * The ids of the sites that can be installed, in alphabetical order.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-an-administrator-must-be-able-to-install-an-example-site-with-one-command
	 */
	public function ids(): array;
}//end interface
