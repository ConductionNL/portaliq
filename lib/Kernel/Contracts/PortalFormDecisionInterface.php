<?php

/**
 * Contract of PortalFormDecision
 *
 * The public surface of PortalFormDecision that other components call, so they depend
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
 * Contract of PortalFormDecision.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFormDecisionInterface {

	/**
	 * Decide one step.
	 *
	 * @param array<string, mixed> $decision The step's `decision`: rule, inputs, output, nextStep.
	 * @param array<string, mixed> $answers The answers so far.
	 *
	 * @return array{status: string, outcome: string, output: string, nextStep: string}
	 *         `status` is DECIDED, UNAVAILABLE or NO_OUTCOME; `nextStep` is the step the outcome opens, or ''.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t05
	 */
	public function decide(array $decision, array $answers): array;
}//end interface
