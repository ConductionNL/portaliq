<?php

/**
 * Portaliq Report Terms Service
 *
 * Where the statutory terms stand for one report: the acknowledgement term and
 * the feedback term the Wet bescherming klokkenluiders obliges, as the case
 * type declares them.
 *
 * Portaliq holds no term of its own and computes none from a constant. A
 * declaration that names no term yields no term, which is the honest answer:
 * inventing seven days because the law usually says seven would be the portal
 * telling a reporter something the organisation never promised.
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

use DateTimeImmutable;

/**
 * Renders the declared terms and where they stand.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportTermsService {
	/**
	 * The property on the case type carrying the declaration.
	 */
	public const DECLARATION = 'portalReportDeclaration';

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
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function forReport(array $caseType, array $report, ?DateTimeImmutable $now = null): array {
		$declaration = ($caseType[self::DECLARATION] ?? null);
		if (is_array($declaration) === false) {
			return [];
		}

		$received = date_create_immutable((string)($report['receivedAt'] ?? ''));
		if ($received === false) {
			return [];
		}

		$moment = ($now ?? new DateTimeImmutable());
		$terms = [];
		foreach ([['acknowledgementDays', 'acknowledgement', 'acknowledgedAt'], ['feedbackDays', 'feedback', 'feedbackAt']] as $declared) {
			[$key, $name, $metField] = $declared;
			$days = (int)($declaration[$key] ?? 0);
			if ($days <= 0) {
				continue;
			}

			$due = $received->modify('+' . $days . ' days');
			$metAt = (string)($report[$metField] ?? '');

			$terms[] = [
				'term' => $name,
				'days' => $days,
				'dueAt' => $due->format(DATE_ATOM),
				'met' => ($metAt !== ''),
				'metAt' => $metAt,
				// Negative means the term has passed. The reporter is told
				// that plainly rather than shown a term that quietly stopped
				// counting.
				'daysLeft' => (int)$moment->diff($due)->format('%r%a'),
			];
		}//end foreach

		return $terms;
	}//end forReport()
}//end class
