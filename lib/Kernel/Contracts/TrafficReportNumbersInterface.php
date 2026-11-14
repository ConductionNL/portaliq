<?php

/**
 * Contract of TrafficReportNumbers
 *
 * The public surface of TrafficReportNumbers that other components call, so they depend
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
 * Contract of TrafficReportNumbers.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrafficReportNumbersInterface {

	/**
	 * The totals and ranked lists of a set of daily records.
	 *
	 * @param array<int, array<string, mixed>> $records The `portalTrafficDaily` records of the period.
	 *
	 * @return array<string, mixed> pageViews, sessions, visitors, engagedSessions, bounceRate, days,
	 *                              pages, sources, devices, browsers, languages, regions, goals,
	 *                              conversionRate, funnels, forms, notFound, errors.
	 *
	 * @spec openspec/specs/portal-traffic-reporting/spec.md#requirement-a-scheduled-report-must-be-sent-once-per-period
	 */
	public function fold(array $records): array;
}//end interface
