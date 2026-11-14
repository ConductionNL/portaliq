<?php

/**
 * Contract of ActivityRoster
 *
 * The public surface of ActivityRoster that other components call, so they depend
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
 * Contract of ActivityRoster.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityRosterInterface {

	/**
	 * The roster staff read: the places, who holds one and the waiting list
	 * in order. Null when the activity is unknown or its sign-ups unreadable.
	 *
	 * @param string $activityId The activity id or slug.
	 *
	 * @return array{places: int, confirmed: array<int, array<string, mixed>>, waitlist: array<int, array<string, mixed>>}|null
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-freed-place-must-go-to-the-child-who-waited-longest
	 */
	public function roster(string $activityId): ?array;
}//end interface
