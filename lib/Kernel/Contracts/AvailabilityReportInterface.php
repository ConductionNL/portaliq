<?php

/**
 * Contract of AvailabilityReport
 *
 * The public surface of AvailabilityReport that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of AvailabilityReport.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AvailabilityReportInterface {

	/**
	 * The report as CSV: one line per month, then one line per outage.
	 *
	 * @param array<string, mixed> $report The report from forPortal().
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function csv(array $report): string;

	/**
	 * The report for one portal over the last full months before now.
	 *
	 * @param string $portal The portal slug.
	 * @param DateTimeImmutable $now The moment the report is asked for.
	 * @param int $months How many full months, 1 to 12.
	 *
	 * @return array{portal: string, from: string, until: string, months: array<int, array<string, mixed>>,
	 *     outages: array<int, array<string, mixed>>, summary: array<string, mixed>}
	 *
	 * @spec openspec/specs/portal-availability/spec.md#requirement-an-administrator-reads-a-twelve-month-report-req-oar-004
	 */
	public function forPortal(string $portal, DateTimeImmutable $now, int $months = 12): array;
}//end interface
