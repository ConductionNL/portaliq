<?php

/**
 * Contract of CaseRowMarker
 *
 * The public surface of CaseRowMarker that other components call, so they depend
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
 * Contract of CaseRowMarker.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseRowMarkerInterface {



	/**
	 * Whether any contribution declares a case collection, and whether any of
	 * those declares a closed marker: what the portal needs to know to show
	 * "My cases" and its "Closed" tab.
	 *
	 * @param array<string, mixed> $aggregate The subject's aggregated manifest.
	 *
	 * @return array{enabled: bool, closedMarker: bool}
	 *
	 * @spec openspec/specs/portal-my-cases/spec.md#requirement-your-cases-from-every-app-in-one-list-req-cmc-001
	 */
	public function announce(array $aggregate): array;
}//end interface
