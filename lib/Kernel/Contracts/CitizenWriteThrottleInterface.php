<?php

/**
 * Contract of CitizenWriteThrottle
 *
 * The public surface of CitizenWriteThrottle that other components call, so they depend
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
 * Contract of CitizenWriteThrottle.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CitizenWriteThrottleInterface {

	/**
	 * Count one write and say whether it is still within both limits. Counting
	 * happens before the answer, so a refused write is itself counted: a caller
	 * hammering the surface does not get a free retry budget by being refused.
	 *
	 * @param string $subjectRef The portal identity making the write.
	 * @param string $caseId The case being written on.
	 *
	 * @return bool True when the write may proceed.
	 *
	 * @spec openspec/specs/citizen-writes-on-their-own-case/spec.md
	 */
	public function allow(string $subjectRef, string $caseId): bool;
}//end interface
