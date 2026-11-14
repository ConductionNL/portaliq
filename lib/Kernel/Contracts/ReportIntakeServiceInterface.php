<?php

/**
 * Contract of ReportIntakeService
 *
 * The public surface of ReportIntakeService that other components call, so they depend
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
 * Contract of ReportIntakeService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportIntakeServiceInterface {

	/**
	 * Accept a report.
	 *
	 * @param string $portal The portal it was filed on.
	 * @param string $caseType The case type governing it.
	 * @param array<string, mixed> $report `subject`, `body` and the rest of the
	 *                                     form's answers.
	 * @param array<string, mixed> $contact What the reporter gave about
	 *                                      themselves, possibly nothing.
	 * @param string $caseTypeRegister The register the case type lives in.
	 * @param string $caseTypeSchema The schema the case type lives in.
	 *
	 * @return array{code: string, reportId: string}|null Null when the portal
	 *         is unknown or the write failed. A report with no contact detail
	 *         is never a failure.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function accept(
		string $portal,
		string $caseType,
		array $report,
		array $contact = [],
		string $caseTypeRegister = '',
		string $caseTypeSchema = '',
	): ?array;
}//end interface
