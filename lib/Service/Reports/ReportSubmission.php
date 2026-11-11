<?php

/**
 * Portaliq Report Submission
 *
 * One report as a form hands it in: the portal and case type it was filed
 * under, the answers, what the reporter gave about themselves, and where the
 * case type's declaration lives.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Reports
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

/**
 * A report handed in on a portal.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
final class ReportSubmission {
	/**
	 * Constructor.
	 *
	 * @param string               $portal           The portal it was filed on.
	 * @param string               $caseType         The case type governing it.
	 * @param array<string, mixed> $report           `subject`, `body` and the rest of the form's answers.
	 * @param array<string, mixed> $contact          What the reporter gave about themselves, possibly nothing.
	 * @param string               $caseTypeRegister The register the case type lives in.
	 * @param string               $caseTypeSchema   The schema the case type lives in.
	 */
	public function __construct(
		public readonly string $portal,
		public readonly string $caseType,
		public readonly array $report,
		public readonly array $contact = [],
		public readonly string $caseTypeRegister = '',
		public readonly string $caseTypeSchema = '',
	) {
	}//end __construct()
}//end class
