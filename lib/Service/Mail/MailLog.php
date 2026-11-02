<?php

/**
 * Portaliq Mail Log (mail-templates-admin-screen)
 *
 * @category Mail
 * @package  OCA\Portaliq\Service\Mail
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
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Mail;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * The log of mails the portal sent, kept 90 days.
 *
 * A row holds the time, the masked recipient, the kind of mail, the case and
 * the status. It never holds the full address or any text of the mail, so a
 * resend rebuilds the mail from the account instead of from the log.
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
 */
class MailLog {
	public const REGISTER = 'portaliq';

	public const SCHEMA = 'portalMailLog';

	/**
	 * How long a row is kept, in days.
	 */
	public const RETENTION_DAYS = 90;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the rows for the retention job.
	 * @param PortalObjectWriter $writer Writes and removes rows.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * Log one send. A failed write is not an error for the mail: the mail has gone.
	 *
	 * @param string $portal        The portal slug.
	 * @param string $templateKey   The kind of mail.
	 * @param string $email         The recipient (only a masked form and a hash are kept).
	 * @param string $status        `queued`, `delivered` or `failed`.
	 * @param string $caseRef       The case or request the mail is about, or ''.
	 * @param string $subjectRef    The portal account the mail went to, or ''.
	 * @param string $failureReason A word for why it failed, or ''.
	 * @param string $retryOf       The log row this one sends again, or ''.
	 *
	 * @return bool True when the row was written.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	public function record(
		string $portal,
		string $templateKey,
		string $email,
		string $status,
		string $caseRef='',
		string $subjectRef='',
		string $failureReason='',
		string $retryOf='',
	): bool {
		if (in_array($status, ['queued', 'delivered', 'failed'], true) === false) {
			return false;
		}

		$row = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'kind' => 'mail',
				'portal' => $portal,
				'templateKey' => $templateKey,
				'recipientMasked' => self::mask(email: $email),
				'recipientHash' => hash('sha256', strtolower(trim($email))),
				'subjectRef' => $subjectRef,
				'caseRef' => $caseRef,
				'status' => $status,
				'failureReason' => $failureReason,
				'sentAt' => (new DateTimeImmutable())->format(DATE_ATOM),
				'retryOf' => $retryOf,
			]
		);

		return $row !== null;
	}//end record()

	/**
	 * Queue a new send of a failed one: a `queued` row that points back at it.
	 *
	 * Only a failed row can be sent again, and only once while a retry waits.
	 * The row keeps no full address, so redelivery rebuilds the mail from the
	 * account the row names.
	 *
	 * @param string $id The failed row's id.
	 *
	 * @return string `queued`, `not_found`, `not_failed` or `error`.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t04
	 */
	public function queueRetry(string $id): string {
		$row = $this->reader->readObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'kind',
			subjectRef: 'mail',
			id: $id
		);
		if ($row === null) {
			return 'not_found';
		}

		if ((string)($row['status'] ?? '') !== 'failed') {
			return 'not_failed';
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'kind' => 'mail',
				'portal' => (string)($row['portal'] ?? ''),
				'templateKey' => (string)($row['templateKey'] ?? ''),
				'recipientMasked' => (string)($row['recipientMasked'] ?? ''),
				'recipientHash' => (string)($row['recipientHash'] ?? ''),
				'subjectRef' => (string)($row['subjectRef'] ?? ''),
				'caseRef' => (string)($row['caseRef'] ?? ''),
				'status' => 'queued',
				'failureReason' => '',
				'sentAt' => (new DateTimeImmutable())->format(DATE_ATOM),
				'retryOf' => $id,
			]
		);
		if ($created === null) {
			return 'error';
		}

		return 'queued';
	}//end queueRetry()

	/**
	 * An address with its middle hidden: "sanne@example.nl" becomes "s***@example.nl".
	 *
	 * @param string $email The address.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t03
	 */
	public static function mask(string $email): string {
		return (new RecipientMask())->mask(email: $email);
	}//end mask()

	/**
	 * Remove the rows older than the retention.
	 *
	 * @param int                    $limit How many rows to read at most.
	 * @param DateTimeImmutable|null $now   The clock.
	 *
	 * @return int How many rows were removed.
	 *
	 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t05
	 */
	public function purgeExpired(int $limit=500, ?DateTimeImmutable $now=null): int {
		$now ??= new DateTimeImmutable();
		$cutoff = $now->modify('-' . self::RETENTION_DAYS . ' days');
		$rows   = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'kind',
			subjectRef: 'mail',
			organisation: '',
			limit: $limit
		);

		$removed = 0;
		foreach ($rows as $row) {
			if (is_array($row) === false || $this->older(row: $row, cutoff: $cutoff) === false) {
				continue;
			}

			$id = $this->idOf(row: $row);
			if ($id === '') {
				continue;
			}

			$gone = $this->writer->deleteObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'kind',
				subjectRef: 'mail',
				organisation: '',
				id: $id
			);
			if ($gone === true) {
				$removed++;
			}
		}

		return $removed;
	}//end purgeExpired()

	/**
	 * Whether a row is older than the cutoff. A row without a readable date is kept.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param DateTimeImmutable    $cutoff The moment before which rows go.
	 *
	 * @return bool
	 */
	private function older(array $row, DateTimeImmutable $cutoff): bool {
		$sent = strtotime((string)($row['sentAt'] ?? ''));
		if ($sent === false) {
			return false;
		}

		return $sent < $cutoff->getTimestamp();
	}//end older()

	/**
	 * The stored id of a row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		foreach ([$row['id'] ?? null, $row['uuid'] ?? null, $self['id'] ?? null, $self['uuid'] ?? null] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()
}//end class
