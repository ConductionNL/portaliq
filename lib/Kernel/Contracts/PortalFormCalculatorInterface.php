<?php

/**
 * Contract of PortalFormCalculator
 *
 * The public surface of PortalFormCalculator that other components call, so they depend
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
 * Contract of PortalFormCalculator.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalFormCalculatorInterface {

	/**
	 * Replace every calculated field's value with the server's own result.
	 *
	 * A value the browser sent for such a field is dropped first. A result
	 * that cannot be worked out (an argument is missing or not a number) leaves
	 * the field out.
	 *
	 * @param array<int, array<string, mixed>> $fields The form's fields.
	 * @param array<string, mixed> $answers The validated answers.
	 *
	 * @return array{answers: array<string, mixed>, computed: array<int, string>} The answers and the names that were calculated.
	 *
	 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t03
	 */
	public function apply(array $fields, array $answers): array;
}//end interface
