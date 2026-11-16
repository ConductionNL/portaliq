<?php

/**
 * Contract of IntakeQueueDrain
 *
 * The public surface of IntakeQueueDrain that other components call, so they depend
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
 * Contract of IntakeQueueDrain.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntakeQueueDrainInterface {

	/**
	 * One pass over the waiting entries.
	 *
	 * @param int $limit How many entries of each state to take.
	 *
	 * @return array{delivered: int, refused: int, waiting: int, reasons: array<string, string>}
	 *         `reasons` holds why each refused entry did not arrive, by reference.
	 *         `waiting` is what is left after the pass; zero means the queue may go (task 4.3).
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function drain(int $limit = 200): array;

	/**
	 * How many entries wait, sending nothing.
	 *
	 * @param int $limit How many entries of each state to count.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function waiting(int $limit = 200): int;
}//end interface
