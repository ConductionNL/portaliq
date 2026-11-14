<?php

/**
 * Contract of TrafficPageReport
 *
 * The public surface of TrafficPageReport that other components call, so they depend
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
 * Contract of TrafficPageReport.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficPageReportInterface {

	/**
	 * Fold the records for one route.
	 *
	 * @param array<int, array<string, mixed>> $records The portal's "all visits" daily records.
	 * @param string                           $route   The page's route, normalised.
	 *
	 * @return array<string, mixed> recordedDays, detailDays, pageViews, entrances, exits,
	 *                              sessions, visitors, engagedSessions, previous, next,
	 *                              referrers, outbound.
	 *
	 * @spec openspec/specs/portal-page-traffic/spec.md#requirement-the-page-endpoint-must-return-one-pages-figures-for-a-period
	 */
	public function fold(array $records, string $route): array;
}//end interface
