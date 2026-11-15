<?php

/**
 * Contract of ExampleResidentSignIn
 *
 * The public surface of ExampleResidentSignIn that other components call, so they depend
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
 * Contract of ExampleResidentSignIn.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExampleResidentSignInInterface {

	/**
	 * Whether the one-click demo sign-in is open at all: the switch is `yes`
	 * and the install records can be read.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/resident-on-example-set/specs/example-resident/spec.md#requirement-the-example-resident-comes-from-the-example-set
	 */
	public function open(): bool;

	/**
	 * The Nextcloud account id the one-click demo sign-in may mint for: the
	 * sign-in is open, and set `$id` installed resident `$resident` of this
	 * portal. An empty `$resident` means the first resident the set declares
	 * for the portal, so a link made before there were several still works.
	 * '' otherwise. The caller checks that the portal offers the `nextcloud` mode.
	 *
	 * @param string               $id       The example set id the site asks for.
	 * @param array<string, mixed> $portal   The portal row.
	 * @param string               $resident The resident's key, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
	 */
	public function userFor(string $id, array $portal, string $resident = ''): string;
}//end interface
