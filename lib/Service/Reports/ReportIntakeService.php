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
 * @spec openspec/specs/report-without-an-account/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Reports;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Accepts a report and issues its receipt code.
 *
 * @spec openspec/specs/report-without-an-account/spec.md
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
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function accept(
		string $portal,
		string $caseType,
		array $report,
		array $contact = [],
		string $caseTypeRegister = '',
		string $caseTypeSchema = '',
	): ?array {
		$submission = new ReportSubmission(
			portal: $portal,
			caseType: $caseType,
			report: $report,
			contact: $contact,
			caseTypeRegister: $caseTypeRegister,
			caseTypeSchema: $caseTypeSchema
		);
		return $this->acceptSubmission(submission: $submission);
	}//end accept()

	/**
	 * Accept a report handed in on a portal.
	 *
	 * @param ReportSubmission $submission The report as the form handed it in.
	 *
	 * @return array{code: string, reportId: string}|null Null when the portal
	 *         is unknown or the write failed. A report with no contact detail
	 *         is never a failure.
	 *
	 * @spec openspec/specs/report-without-an-account/spec.md
	 */
	public function acceptSubmission(ReportSubmission $submission): ?array {
		if ($submission->portal === '') {
			return null;
		}

		$code = $this->random->generate(self::CODE_LENGTH, (ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_DIGITS));
		if ($code === '') {
			return null;
		}

		$answers = $this->formAnswers(report: $submission->report);
		$filed   = ['answers' => $this->withoutContactFields(answers: $answers), 'codeHash' => hash('sha256', $code)];
		$created = $this->createReport(submission: $submission, filed: $filed);
		if ($created === null) {
			return null;
		}

		$reportId = ReportRowId::of(row: $created);
		$this->linkContact(reportId: $reportId, contact: $this->givenContact(contact: $submission->contact, answers: $answers));

		// The code travels back to the caller once, for the confirmation page.
		// It is not stored, not mailed and not recoverable.
		return ['code' => $code, 'reportId' => $reportId];
	}//end acceptSubmission()

	/**
	 * The form's answers without the subject and body, and without anything
	 * that identifies the machine it came from: no address, no user agent, no
	 * session.
	 *
	 * @param array<string, mixed> $report Everything the form sent.
	 *
	 * @return array<string, mixed>
	 */
	private function formAnswers(array $report): array {
		$answers = $report;
		unset($answers['subject'], $answers['body']);
		unset($answers['ip'], $answers['address'], $answers['userAgent']);
		return $answers;
	}//end formAnswers()

	/**
	 * What the reporter gave about themselves. A form that asks for a name or
	 * an address puts it in the answers, and answers live on the report, so a
	 * contact field found there is MOVED into the separate record (see
	 * withoutContactFields()). Anything the caller passed as contact wins,
	 * because that is the field the form meant as contact.
	 *
	 * @param array<string, mixed> $contact What the caller passed as contact.
	 * @param array<string, mixed> $answers The form's answers.
	 *
	 * @return array<string, mixed>
	 */
	private function givenContact(array $contact, array $answers): array {
		$given = $contact;
		foreach (self::CONTACT_FIELDS as $field) {
			if (isset($answers[$field]) === true) {
				$given[$field] = ($given[$field] ?? $answers[$field]);
			}
		}

		return $given;
	}//end givenContact()

	/**
	 * The answers with every contact field taken out: those live in the
	 * separate record, never on the report.
	 *
	 * @param array<string, mixed> $answers The form's answers.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutContactFields(array $answers): array {
		foreach (self::CONTACT_FIELDS as $field) {
			if (isset($answers[$field]) === true) {
				unset($answers[$field]);
			}
		}

		return $answers;
	}//end withoutContactFields()

	/**
	 * Write the report row.
	 *
	 * @param ReportSubmission $submission The report as the form handed it in.
	 * @param array{answers: array<string, mixed>, codeHash: string} $filed The answers to keep and the receipt code's hash.
	 *
	 * @return array<string, mixed>|null The created row, or null when the write failed.
	 */
	private function createReport(ReportSubmission $submission, array $filed): ?array {
		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: $this->reportRow(submission: $submission, filed: $filed)
		);
	}//end createReport()

	/**
	 * The fields of a new report row. The contact is linked afterwards.
	 *
	 * @param ReportSubmission $submission The report as the form handed it in.
	 * @param array{answers: array<string, mixed>, codeHash: string} $filed The answers to keep and the receipt code's hash.
	 *
	 * @return array<string, mixed>
	 */
	private function reportRow(ReportSubmission $submission, array $filed): array {
		return [
			'portal' => $submission->portal,
			'caseType' => $submission->caseType,
			// Where the declaration lives, so the thread can read the
			// custodian and the terms again without the reporter carrying
			// anything but their code.
			'caseTypeRegister' => $submission->caseTypeRegister,
			'caseTypeSchema' => $submission->caseTypeSchema,
			'subject' => (string)($submission->report['subject'] ?? ''),
			'body' => (string)($submission->report['body'] ?? ''),
			'answers' => $filed['answers'],
			'codeHash' => $filed['codeHash'],
			'contactRef' => '',
			'state' => 'open',
			'receivedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];
	}//end reportRow()

	/**
	 * Store what the reporter gave and point the report at it, when they gave
	 * anything.
	 *
	 * @param string $reportId The report.
	 * @param array<string, mixed> $contact What they gave.
	 *
	 * @return void
	 */
	private function linkContact(string $reportId, array $contact): void {
		$contactId = $this->storeContact(reportId: $reportId, contact: $contact);
		if ($contactId === '') {
			return;
		}

		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $reportId,
			data: ['contactRef' => $contactId]
		);
	}//end linkContact()

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

		return ReportRowId::of(row: $created);
	}//end storeContact()
}//end class
