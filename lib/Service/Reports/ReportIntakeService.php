<?php

/**
 * Portaliq Report Intake Service
 *
 * Accepting a report of wrongdoing from somebody who says nothing about
 * themselves, and giving them the one key back in.
 *
 * Three rules shape everything here. Nothing is required of the reporter: no
 * session, no address, no verification, and a report with no contact detail at
 * all is a complete report. The receipt code is cryptographic, shown once and
 * stored only as a hash, so nobody, including this code, can send it again.
 * And whatever the reporter did give lives in a separate record joined by
 * reference, never as fields on the report, so a read of the report cannot
 * return it by accident.
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
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Accepts a report and issues its receipt code.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportIntakeService {
	/**
	 * The register the report lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a report.
	 */
	private const SCHEMA = 'portalReport';

	/**
	 * The schema holding what the reporter gave about themselves.
	 */
	private const CONTACT_SCHEMA = 'portalReporterContact';

	/**
	 * The contact fields a reporter may leave. Anything else a form sends is
	 * not stored: the identity record is small on purpose.
	 */
	private const CONTACT_FIELDS = ['name', 'email', 'phone'];

	/**
	 * How long the receipt code is, in characters.
	 */
	private const CODE_LENGTH = 16;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $writer Records the report and the contact.
	 * @param ISecureRandom $random Mints the receipt code.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function accept(
		string $portal,
		string $caseType,
		array $report,
		array $contact = [],
		string $caseTypeRegister = '',
		string $caseTypeSchema = '',
	): ?array {
		if ($portal === '') {
			return null;
		}

		$code = $this->random->generate(self::CODE_LENGTH, (ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS));
		if ($code === '') {
			return null;
		}

		$answers = $report;
		unset($answers['subject'], $answers['body']);
		// Whatever else the form sent, nothing that identifies the machine it
		// came from is kept: no address, no user agent, no session.
		unset($answers['ip'], $answers['address'], $answers['userAgent']);

		// A form that asks for a name or an address puts it in the answers,
		// and answers live on the report. So a contact field found there is
		// MOVED into the separate record rather than left where a read of the
		// report would return it. Anything the caller passed as contact wins,
		// because that is the field the form meant as contact.
		$given = $contact;
		foreach (self::CONTACT_FIELDS as $field) {
			if (isset($answers[$field]) === true) {
				$given[$field] = ($given[$field] ?? $answers[$field]);
				unset($answers[$field]);
			}
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'portal' => $portal,
				'caseType' => $caseType,
				// Where the declaration lives, so the thread can read the
				// custodian and the terms again without the reporter carrying
				// anything but their code.
				'caseTypeRegister' => $caseTypeRegister,
				'caseTypeSchema' => $caseTypeSchema,
				'subject' => (string)($report['subject'] ?? ''),
				'body' => (string)($report['body'] ?? ''),
				'answers' => $answers,
				'codeHash' => hash('sha256', $code),
				'contactRef' => '',
				'state' => 'open',
				'receivedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		$reportId = $this->idOf(row: $created);
		$contactId = $this->storeContact(reportId: $reportId, contact: $given);
		if ($contactId !== '') {
			$this->writer->updateObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: '',
				subjectRef: '',
				organisation: '',
				id: $reportId,
				data: ['contactRef' => $contactId]
			);
		}

		// The code travels back to the caller once, for the confirmation page.
		// It is not stored, not mailed and not recoverable.
		return ['code' => $code, 'reportId' => $reportId];
	}//end accept()

	/**
	 * Store what the reporter gave, as its own record.
	 *
	 * @param string $reportId The report it belongs to.
	 * @param array<string, mixed> $contact What they gave.
	 *
	 * @return string The contact record's id, or '' when they gave nothing.
	 */
	private function storeContact(string $reportId, array $contact): string {
		$values = [];
		foreach (self::CONTACT_FIELDS as $field) {
			$value = trim((string)($contact[$field] ?? ''));
			if ($value !== '') {
				$values[$field] = $value;
			}
		}

		if ($values === [] || $reportId === '') {
			return '';
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::CONTACT_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: array_merge(['reportRef' => $reportId], $values)
		);
		if ($created === null) {
			return '';
		}

		return $this->idOf(row: $created);
	}//end storeContact()

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
