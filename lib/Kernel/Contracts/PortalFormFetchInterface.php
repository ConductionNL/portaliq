<?php

/**
 * Contract of PortalFormFetch
 *
 * The public surface of PortalFormFetch that other components call, so they depend
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
 * Contract of PortalFormFetch.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFormFetchInterface {
	/**
	 * The fetch a step declares under an id, or null when it declares none.
	 *
	 * @param array<int, array<string, mixed>> $steps The form's steps.
	 * @param string                           $step  The step's id.
	 * @param string                           $fetch The fetch's id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function declared(array $steps, string $step, string $fetch): ?array;

	/**
	 * Run one declared fetch with the answers so far.
	 *
	 * @param array<string, mixed> $fetch   The declared fetch.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return array{status: string, values?: array<string, mixed>, check?: string|null, fetchedAt?: string, reason?: string}
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function run(array $fetch, array $answers): array;

	/**
	 * The answers with every field a fetch fills taken from a fresh fetch, or
	 * null when one of the form's fetches did not answer.
	 *
	 * @param array<int, array<string, mixed>> $steps   The form's steps.
	 * @param array<string, mixed>             $answers The validated answers.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t05
	 */
	public function filled(array $steps, array $answers): ?array;
}//end interface
