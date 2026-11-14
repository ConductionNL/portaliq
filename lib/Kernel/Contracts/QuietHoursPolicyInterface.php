<?php

/**
 * Contract of QuietHoursPolicy
 *
 * The public surface of QuietHoursPolicy that other components call, so they depend
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
 * Contract of QuietHoursPolicy.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuietHoursPolicyInterface {

	/**
	 * A subject's own configured window, or the documented default.
	 *
	 * @param string $subjectRef The subject's own subjectRef.
	 *
	 * @return array{start: string, end: string}
	 *
	 * @spec openspec/changes/archive/2026-09-28-push-notifications-quiet-hours/design.md#api-design
	 */
	public function resolveWindow(string $subjectRef): array;

	/**
	 * Set a subject's own quiet-hours window (`HH:MM` 24h strings).
	 *
	 * @param string $subjectRef The subject's own subjectRef (guardian or staff).
	 * @param string $start Window start, `HH:MM`.
	 * @param string $end Window end, `HH:MM`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-09-28-push-notifications-quiet-hours/design.md#api-design
	 */
	public function setWindow(string $subjectRef, string $start, string $end): bool;
}//end interface
