<?php

/**
 * Contract of LandingSubmissionDrain
 *
 * The public surface of LandingSubmissionDrain that other components call, so they depend
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
 * Contract of LandingSubmissionDrain.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LandingSubmissionDrainInterface {

	/**
	 * Drain up to `$limit` stored submissions.
	 *
	 * @param int $limit How many to read.
	 *
	 * @return array{delivered: int, refused: int, reasons: array<string, string>, waiting: int}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function drain(int $limit = 200): array;

	/**
	 * How many stored submissions wait.
	 *
	 * @param int $limit How many to read.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/tasks.md#4.1
	 */
	public function waiting(int $limit = 200): int;
}//end interface
