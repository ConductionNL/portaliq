<?php

/**
 * Portaliq Report Projection
 *
 * The one place a report becomes something to send back, so that "the reporter
 * is not in the answer" is a property of a single function rather than a habit
 * spread over four controllers.
 *
 * Everything that could identify the reporter is removed here: the reference
 * to the separate contact record, any address the transport may have left on
 * the row, and the hash of the receipt code. A reader, a list, a search result
 * and an export all come through this, which is why an export cannot quietly
 * grow an identity column later.
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
 * Projects a report for anybody who is not the custodian answering a reveal.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportProjection {
	/**
	 * Keys that never leave, whoever is asking.
	 *
	 * `contactRef` is the join to the identity record, and a reference is an
	 * identifier: handing it out turns "held apart" into "one more call away".
	 * `codeHash` is the reporter's credential in hashed form. The address keys
	 * are never written, and are stripped here as well so a row that somehow
	 * carries one cannot serve it.
	 */
	public const WITHHELD = ['contactRef', 'codeHash', 'ip', 'address', 'userAgent', 'clientAddress'];

	/**
	 * The fields the answer carries, in the order a reader expects them.
	 */
	private const CARRIED = ['portal', 'caseType', 'subject', 'body', 'answers', 'state', 'acknowledgedAt', 'feedbackAt', 'receivedAt'];

	/**
	 * One report, as anybody but the custodian may read it.
	 *
	 * @param array<string, mixed> $report The stored row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function one(array $report): array {
		$out = ['id' => $this->idOf(row: $report)];
		foreach (self::CARRIED as $key) {
			if (array_key_exists($key, $report) === true) {
				$out[$key] = $report[$key];
			}
		}

		// Belt and braces: CARRIED is an allow-list already, so nothing
		// withheld can be in $out. The assertion is kept because the cost of
		// being wrong here is a reporter's name in a list.
		$out = array_diff_key($out, array_flip(self::WITHHELD));

		// `answers` is whatever the form sent. A form that asked for a name
		// would put it here, so the sweep runs over it too.
		if (isset($out['answers']) === true && is_array($out['answers']) === true) {
			foreach (self::WITHHELD as $key) {
				unset($out['answers'][$key]);
			}
		}

		return $out;
	}//end one()

	/**
	 * A list of reports, each projected the same way.
	 *
	 * @param array<int, mixed> $reports The stored rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function many(array $reports): array {
		$out = [];
		foreach ($reports as $report) {
			if (is_array($report) === true) {
				$out[] = $this->one(report: $report);
			}
		}

		return $out;
	}//end many()

	/**
	 * A row's own identifier.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()
}//end class
