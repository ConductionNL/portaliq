<?php

/**
 * Contract of ExampleResidentRemover
 *
 * The public surface of ExampleResidentRemover that other components call, so they depend
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
 * Contract of ExampleResidentRemover.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExampleResidentRemoverInterface {

	/**
	 * Delete what the install recorded, and nothing else.
	 *
	 * The objects go last-written first, so a message goes before the case it
	 * is about.
	 *
	 * @param string $id   The declaration's id.
	 * @param string $slug The slug of its portal.
	 *
	 * @return array<string, mixed> `recorded` (false when there is no record), `available`, `userId`, `types`
	 *                              (per register and schema: `deleted` and `gone`), `failed`, and `account`,
	 *                              `signIn` and `user`: `deleted`/`withdrawn`, `kept-not-ours` or `failed`.
	 *
	 * @spec openspec/changes/example-resident-zuiddrecht/specs/example-resident/spec.md#requirement-an-administrator-must-be-able-to-remove-an-example-resident
	 */
	public function remove(string $id, string $slug): array;
}//end interface
