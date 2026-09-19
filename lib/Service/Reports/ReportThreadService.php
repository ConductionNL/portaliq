<?php

/**
 * Portaliq Report Thread Service
 *
 * The conversation a reporter keeps with the organisation, against nothing but
 * their receipt code.
 *
 * The code is the whole identity here, so two things follow. A wrong code is
 * registered with Nextcloud's own throttler before anything else happens, and
 * the caller is delayed: guessing a sixteen-character code has to cost
 * something. And the reporter's view of the thread is filtered server-side on
 * what the contribution declared visible: an internal note is a message with
 * that flag false and is never in the answer, rather than hidden by the page
 * that renders it.
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
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\Bruteforce\IThrottler;

/**
 * Opens a report's thread on a code, and carries messages both ways.
 *
 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
 */
class ReportThreadService {
	/**
	 * The throttler action a failed code attempt is registered under.
	 */
	public const THROTTLE_ACTION = 'portaliqReportCode';

	/**
	 * The register the report lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a report.
	 */
	private const SCHEMA = 'portalReport';

	/**
	 * The schema recording one message on a thread.
	 */
	private const MESSAGE_SCHEMA = 'portalReportMessage';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the report and its messages.
	 * @param PortalObjectWriter $writer Writes a message on the thread.
	 * @param IThrottler $throttler Nextcloud's own brute-force throttler.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly IThrottler $throttler,
	) {
	}//end __construct()

	/**
	 * The report a code opens, or null.
	 *
	 * @param string $code The receipt code.
	 * @param string $address The caller's address, for the throttler only. It
	 *                        is never stored against the report.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function openByCode(string $code, string $address = ''): ?array {
		if ($code === '') {
			$this->registerFailure(address: $address);
			return null;
		}

		$hash = hash('sha256', $code);
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'codeHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);

		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['codeHash'] ?? ''), $hash) === true) {
				return $row;
			}
		}

		// Every failed attempt costs, and a lost code is not recoverable: there
		// is deliberately no branch here that helps somebody in.
		$this->registerFailure(address: $address);

		return null;
	}//end openByCode()

	/**
	 * The thread as the reporter may read it.
	 *
	 * @param string $reportId The report.
	 *
	 * @return array<int, array<string, mixed>> Only the messages declared
	 *         visible to the reporter, oldest first.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function messagesForReporter(string $reportId): array {
		$out = [];
		foreach ($this->allMessages(reportId: $reportId) as $message) {
			if (($message['visibleToReporter'] ?? false) !== true && (string)($message['author'] ?? '') !== 'reporter') {
				// An internal note never reaches the reporter's answer at all.
				continue;
			}

			$out[] = [
				'author' => (string)($message['author'] ?? ''),
				'authorName' => (string)($message['authorName'] ?? ''),
				'body' => (string)($message['body'] ?? ''),
				'writtenAt' => (string)($message['writtenAt'] ?? ''),
			];
		}

		return $out;
	}//end messagesForReporter()

	/**
	 * Every message on a thread, for the handler's own view.
	 *
	 * @param string $reportId The report.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function allMessages(string $reportId): array {
		if ($reportId === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::MESSAGE_SCHEMA,
			scopeField: 'reportRef',
			subjectRef: $reportId,
			organisation: '',
			limit: 200
		);

		$messages = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['reportRef'] ?? '') === $reportId) {
				$messages[] = $row;
			}
		}

		usort(
			$messages,
			static function (array $first, array $second): int {
				return strcmp((string)($first['writtenAt'] ?? ''), (string)($second['writtenAt'] ?? ''));
			}
		);

		return $messages;
	}//end allMessages()

	/**
	 * Write a message on the thread.
	 *
	 * A reporter's own message is always visible to them; a handler's is
	 * visible only when they said so, because the default for a note on a
	 * whistleblowing report has to be internal.
	 *
	 * @param string $reportId The report.
	 * @param string $author `reporter` or `handler`.
	 * @param string $body The message.
	 * @param bool $visibleToReporter Whether the reporter may read it.
	 * @param string $authorName The handler's name, when a handler wrote it.
	 *
	 * @return bool True when the message landed.
	 *
	 * @spec openspec/changes/a-report-without-an-account-and-a-custodian-who-may-reveal-it/specs/report-without-an-account/spec.md
	 */
	public function write(string $reportId, string $author, string $body, bool $visibleToReporter = false, string $authorName = ''): bool {
		if ($reportId === '' || trim($body) === '' || in_array($author, ['reporter', 'handler'], true) === false) {
			return false;
		}

		$visible = $visibleToReporter;
		$name = $authorName;
		if ($author === 'reporter') {
			$visible = true;
			// The reporter's own name is never recorded on a message: the
			// whole point is that there is none.
			$name = '';
		}

		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::MESSAGE_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'reportRef' => $reportId,
				'author' => $author,
				'authorName' => $name,
				'body' => trim($body),
				'visibleToReporter' => $visible,
				'writtenAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		) !== null;
	}//end write()

	/**
	 * Register a failed attempt, and let the throttler delay the caller.
	 *
	 * @param string $address The caller's address.
	 *
	 * @return void
	 */
	private function registerFailure(string $address): void {
		if ($address === '') {
			return;
		}

		$this->throttler->registerAttempt(self::THROTTLE_ACTION, $address);
		$this->throttler->sleepDelayOrThrowOnMax($address, self::THROTTLE_ACTION);
	}//end registerFailure()
}//end class
