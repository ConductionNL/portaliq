<?php

/**
 * Contract of PartnerAskService
 *
 * The public surface of PartnerAskService that other components call, so they depend
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
 * Contract of PartnerAskService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PartnerAskServiceInterface {

	/**
	 * Ask a partner for something from a case.
	 *
	 * @param array<string, mixed> $handler The handler's subject shape, used
	 *                                      for the server-to-server assertion.
	 * @param array<string, mixed> $partner `subjectRef`, or `kvk` plus `email`
	 *                                      and optionally `name`.
	 * @param array<string, mixed> $ask `title`, `description`, `dueAt`,
	 *                                  `uploadRules`, and the case tuple
	 *                                  `register`, `schema`, `caseId`.
	 *
	 * @return array{subjectRef: string, provisioned: bool, task: array<string, mixed>}|null
	 *         Null when the ask carries no title, no partner that can be
	 *         resolved, or when the engine refused the task.
	 *
	 * @spec openspec/specs/partner-tasks-in-the-portal/spec.md
	 */
	public function ask(array $handler, array $partner, array $ask): ?array;
}//end interface
