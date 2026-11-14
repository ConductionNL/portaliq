<?php

/**
 * Contract of ReportTermsService
 *
 * The public surface of ReportTermsService that other components call, so they depend
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
 * Contract of ReportTermsService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportTermsServiceInterface {

	/**
	 * Where each declared term stands for one report.
	 *
	 * @param array<string, mixed> $caseType The case type, or [] when unknown.
	 * @param array<string, mixed> $report The report.
	 * @param DateTimeImmutable|null $now The moment to measure from.
	 *
	 * @return array<int, array<string, mixed>> One entry per DECLARED term:
	 *         its name, its days, its due date, whether it is met, and the
	 *         days left or over. A term nobody declared is simply absent.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function forReport(array $caseType, array $report, ?DateTimeImmutable $now = null): array;
}//end interface
