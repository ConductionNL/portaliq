<?php

/**
 * Contract of ActivityPlaces
 *
 * The public surface of ActivityPlaces that other components call, so they depend
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
 * Contract of ActivityPlaces.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActivityPlacesInterface {

	/**
	 * The places an activity has: the lower of its capacity and its distinct
	 * supervisors times `childrenPerSupervisor`, or its capacity when no ratio
	 * is set. Never negative.
	 *
	 * @param array<string, mixed> $activity The activity row.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-09-28-extracurricular-activity-offer/specs/portaliq-cms/spec.md#requirement-a-term-long-activity-must-be-offered-with-places-set-by-capacity-and-supervision
	 */
	public function placesFor(array $activity): int;
}//end interface
