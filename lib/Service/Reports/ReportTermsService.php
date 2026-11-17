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
 * @spec openspec/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

use DateTimeImmutable;
use OCA\Portaliq\Kernel\Contracts\ReportTermsServiceInterface;

/**
 * Renders the declared terms and where they stand.
 *
 * @spec openspec/specs/report-without-an-account/spec.md
 */
class ReportTermsService implements ReportTermsServiceInterface {
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
	 * @spec openspec/specs/report-without-an-account/spec.md
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
		return $this->terms(declaration: $declaration, report: $report, received: $received, moment: $moment);
	}//end forReport()

	/**
	 * One entry per declared term with a positive number of days.
	 *
	 * @param array<string, mixed> $declaration The case type's report declaration.
	 * @param array<string, mixed> $report      The report.
	 * @param DateTimeImmutable    $received    When the report was received.
	 * @param DateTimeImmutable    $moment      The moment to measure from.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	private function terms(array $declaration, array $report, DateTimeImmutable $received, DateTimeImmutable $moment): array {
		$terms = [];
		foreach ([['acknowledgementDays', 'acknowledgement', 'acknowledgedAt'], ['feedbackDays', 'feedback', 'feedbackAt']] as $declared) {
			[$key, $name, $metField] = $declared;
			$days = (int)($declaration[$key] ?? 0);
			if ($days <= 0) {
				continue;
			}

			$due = $received->modify('+' . $days . ' days');
			// Negative means the term has passed. The reporter is told
			// that plainly rather than shown a term that quietly stopped
			// counting.
			$terms[] = $this->term(name: $name, days: $days, due: $due, metAt: (string)($report[$metField] ?? ''))
				+ ['daysLeft' => (int)$moment->diff($due)->format('%r%a')];
		}

		return $terms;
	}//end terms()

	/**
	 * A term's name, days, due date and whether and when it was met.
	 *
	 * @param string            $name  The term's name.
	 * @param int               $days  The term's days.
	 * @param DateTimeImmutable $due   The term's due date.
	 * @param string            $metAt When it was met, or ''.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	private function term(string $name, int $days, DateTimeImmutable $due, string $metAt): array {
		return [
			'term' => $name,
			'days' => $days,
			'dueAt' => $due->format(DATE_ATOM),
			'met' => ($metAt !== ''),
			'metAt' => $metAt,
		];
	}//end term()
}//end class
