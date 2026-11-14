<?php

/**
 * Portaliq WMEBV Submission Receipt Service
 *
 * Generates the WMEBV (in force 2026-01-01, BWBR0048252) post-submit
 * compliance layer for every successful portal create-action: an automatic
 * ontvangstbevestiging (`portalMessage`) in the subject's own inbox — the
 * SAME inbox portal-inbox-v2 aggregates, reused rather than duplicated — and
 * a linked `portalSubmission` proof-of-receipt log record satisfying the
 * burden-of-proof duty (~Awb 2:25). Both writes go through the ALREADY-
 * WHITELISTED field map the caller persisted (never the raw client body),
 * which is itself the WMEBV "copy of submitted data" the subject is entitled
 * to.
 *
 * Failure isolation (design.md): the domain create is the authoritative
 * write and has ALREADY succeeded by the time record() is called. Every
 * failure in here — a thrown exception, or PortalObjectWriter degrading to
 * null — is caught, logged, and never propagated to the caller, so a WMEBV
 * side-effect can never turn a successful submission into a failed one. A
 * failed proof-log write falls back to a minimal record carrying
 * `deliveryStatus: failed`, so the submission stays retriable rather than
 * silently missing from the burden-of-proof log entirely.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
 * @spec openspec/specs/supplier-portal/spec.md#a-receipt-or-log-failure-never-loses-the-submission
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/changes/submission-records-its-action-title/specs/supplier-portal/spec.md#requirement-a-proof-log-record-names-the-submitted-action-as-the-resident-read-it
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Kernel\Contracts\SubmissionReceiptServiceInterface;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fail-safe generator of the WMEBV ontvangstbevestiging + proof-of-receipt log.
 *
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 */
class SubmissionReceiptService implements SubmissionReceiptServiceInterface {
	use RowIdOf;

	/**
	 * The register every portal-owned schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The subject-inbox message schema (portal-inbox-v2 reuses/aggregates this).
	 */
	private const MESSAGE_SCHEMA = 'portalMessage';

	/**
	 * The append-only burden-of-proof log schema.
	 */
	private const SUBMISSION_SCHEMA = 'portalSubmission';

	/**
	 * The longest action title kept on a proof-log record.
	 */
	private const TITLE_MAX_LENGTH = 200;

	/**
	 * The receipt subject-line translation key. English key text per
	 * convention — `l10n/nl.json` supplies the Dutch translation for this
	 * EXACT string; `l10n/en.json` maps it to itself.
	 */
	private const SUBJECT_KEY = 'Confirmation of receipt, reference %1$s';

	/**
	 * The receipt body-text translation key (B1 language level). English key
	 * text per convention — translated in `l10n/nl.json`.
	 */
	private const BODY_KEY = 'We received your submission on %2$s (reference %1$s). '
		. 'This message confirms receipt; we will contact you if further action is needed.';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter $writer Subject-scoped OR writer (same
	 *                                   one the create used).
	 * @param PortalNoticeLanguage $language The translations in the language
	 *                                      of the resident's portal (portal
	 *                                      subjects are not NC users).
	 * @param ITimeFactory $timeFactory Testable clock for the ISO-8601
	 *                                  timestamps.
	 * @param LoggerInterface $logger The logger.
	 * @param NotificationDispatchService $notificationDispatch Fires the `message.created` trigger
	 *                                                          (portal-notifications-dispatch)
	 *                                                          on a successful receipt write; itself
	 *                                                          fail-safe, never affects this call.
	 * @param IDateTimeZone|null $timeZone The instance's time zone, in which the receipt names the moment.
	 *
	 * @spec openspec/changes/receipt-date-reads-in-the-portals-language/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-names-the-moment-in-words-of-the-portals-language
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly PortalNoticeLanguage $language,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
		private readonly NotificationDispatchService $notificationDispatch,
		private readonly ?IDateTimeZone $timeZone = null,
	) {
	}//end __construct()

	/**
	 * Record the WMEBV receipt + proof log for one successful create-action.
	 *
	 * Never throws and never returns a value the caller could branch on — by
	 * design, a WMEBV side-effect can only ever be a follow-on to an ALREADY
	 * successful domain write, never a gate on it.
	 *
	 * @param string $subjectRef The submitting subject (server-derived).
	 * @param string $organisation The subject's tenant (may be empty).
	 * @param string $appId The contributing app the action belongs to.
	 * @param string $actionId The declared action id that was submitted.
	 * @param array<string, mixed> $whitelistedData The ALREADY-whitelisted field map the
	 *                                              domain create persisted (never the raw
	 *                                              client body) — the WMEBV "copy of
	 *                                              submitted data".
	 * @param string $audience The subject's audience (portal-notifications-
	 *                         dispatch — resolves the app manifest via the
	 *                         registry; empty is safe, it simply yields no
	 *                         notification match).
	 * @param mixed $actionTitle The action's name as the resident read it,
	 *                           stored on the proof log as text; anything
	 *                           but a non-blank string records no title.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
	 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
	 * @spec openspec/specs/supplier-portal/spec.md#a-receipt-or-log-failure-never-loses-the-submission
	 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
	 * @spec openspec/changes/submission-records-its-action-title/specs/supplier-portal/spec.md#requirement-a-proof-log-record-names-the-submitted-action-as-the-resident-read-it
	 */
	public function record(
		string $subjectRef,
		string $organisation,
		string $appId,
		string $actionId,
		array $whitelistedData,
		string $audience = '',
		mixed $actionTitle = '',
	): void {
		$run = new ReceiptRun(subjectRef: $subjectRef, organisation: $organisation);
		$run->named           = $this->titleFields(actionTitle: $actionTitle);
		$run->appId           = $appId;
		$run->actionId        = $actionId;
		$run->whitelistedData = $whitelistedData;
		$run->audience        = $audience;
		try {
			$this->doRecord(run: $run);
		} catch (Throwable $e) {
			$this->recordingFailedEntirely(run: $run, error: $e);
		}
	}//end record()

	/**
	 * Write the receipt again for one stored proof-log record whose receipt
	 * failed or is still pending, and mark the record delivered.
	 *
	 * The record is not copied and its proof fields do not move: the receipt
	 * is written from the record's own `subjectRef`, `organisation` and
	 * `payloadCopy`, keeps the record's reference when it has one, and names
	 * the record's `submittedAt` as the moment of submission. Only
	 * `receiptMessageRef` and `deliveryStatus` are written back. A receipt
	 * write that fails leaves the record `failed`. Never throws.
	 *
	 * @param array<string, mixed> $record The stored `portalSubmission` row, as the caller may read it.
	 *
	 * @return bool True when the receipt was written and the record marked delivered.
	 *
	 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-register-a-failed-receipt-again
	 */
	public function registerAgain(array $record): bool {
		$id  = $this->idOf(row: $record);
		$run = new ReceiptRun(subjectRef: (string)($record['subjectRef'] ?? ''), organisation: (string)($record['organisation'] ?? ''));
		if ($id === '' || $run->subjectRef === '') {
			$this->logger->warning('Portaliq: a submission without an id or subject cannot be registered again', ['id' => $id]);
			return false;
		}

		$run->appId           = (string)($record['appId'] ?? '');
		$run->actionId        = (string)($record['actionId'] ?? '');
		$run->whitelistedData = (array)($record['payloadCopy'] ?? []);
		$run->referenceId     = (string)($record['receiptMessageRef'] ?? '');
		if ($run->referenceId === '') {
			$run->referenceId = $this->generateReferenceId();
		}

		$run->submittedAt = (string)($record['submittedAt'] ?? '');
		if ($run->submittedAt === '') {
			$run->submittedAt = $this->now();
		}

		try {
			$message = $this->writeReceiptMessage(run: $run, data: (['receivedAt' => $this->now()] + $this->receiptMessageData(run: $run)));
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: registering a receipt again failed', ['id' => $id, 'reason' => $e->getMessage()]);
			$message = null;
		}

		if ($message === null) {
			$this->markSubmission(run: $run, id: $id, values: ['deliveryStatus' => 'failed']);
			return false;
		}

		return $this->markSubmission(run: $run, id: $id, values: ['receiptMessageRef' => $run->referenceId, 'deliveryStatus' => 'delivered']);
	}//end registerAgain()

	/**
	 * Write the service's own fields back onto a stored proof-log record.
	 *
	 * @param ReceiptRun           $run    The receipt, for the record's subject and tenant.
	 * @param string               $id     The record.
	 * @param array<string, mixed> $values Only `receiptMessageRef` and `deliveryStatus`.
	 *
	 * @return bool True when the record was written.
	 */
	private function markSubmission(ReceiptRun $run, string $id, array $values): bool {
		try {
			$saved = $this->writer->updateObject(
				register: self::REGISTER,
				schema: self::SUBMISSION_SCHEMA,
				scopeField: 'subjectRef',
				subjectRef: $run->subjectRef,
				organisation: $run->organisation,
				id: $id,
				data: $values
			);
		} catch (Throwable $e) {
			$saved = null;
		}

		if ($saved === null) {
			$this->logger->warning('Portaliq: a submission could not be marked after registering its receipt again', ['id' => $id]);
			return false;
		}

		return true;
	}//end markSubmission()

	/**
	 * Belt-and-braces: doRecord()'s own writes already degrade to null rather
	 * than throw, but nothing here may ever reach the caller — a submission
	 * must never be lost because a compliance side-effect misbehaved.
	 *
	 * @param ReceiptRun $run   The receipt.
	 * @param Throwable  $error What went wrong.
	 *
	 * @return void
	 */
	private function recordingFailedEntirely(ReceiptRun $run, Throwable $error): void {
		$this->logger->error(
			'Portaliq: WMEBV receipt recording failed entirely',
			['appId' => $run->appId, 'actionId' => $run->actionId, 'reason' => $error->getMessage()]
		);
		$this->writeFallbackSubmission(run: $run);
	}//end recordingFailedEntirely()

	/**
	 * The proof-log fields that name the action: `actionTitle`, trimmed and
	 * kept to TITLE_MAX_LENGTH characters, or nothing for a title that is no
	 * string or is blank.
	 *
	 * @param mixed $actionTitle The title the caller passed.
	 *
	 * @return array<string, string> Either `['actionTitle' => ...]` or empty.
	 *
	 * @spec openspec/changes/submission-records-its-action-title/specs/supplier-portal/spec.md#requirement-a-proof-log-record-names-the-submitted-action-as-the-resident-read-it
	 */
	private function titleFields(mixed $actionTitle): array {
		if (is_string($actionTitle) === false) {
			return [];
		}

		$title = mb_substr(trim($actionTitle), 0, self::TITLE_MAX_LENGTH);
		if ($title === '') {
			return [];
		}

		return ['actionTitle' => $title];
	}//end titleFields()

	/**
	 * Write the receipt message, then the linked proof-log record.
	 *
	 * @param ReceiptRun $run The receipt.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-a-notification-mail-and-a-task-notice-are-written-in-the-portals-language-only
	 */
	private function doRecord(ReceiptRun $run): void {
		$run->referenceId = $this->generateReferenceId();
		$run->submittedAt = $this->now();

		$message        = $this->writeReceiptMessage(run: $run, data: $this->receiptMessageData(run: $run));
		$deliveryStatus = $this->announceReceipt(message: $message, run: $run);
		$submission     = $this->writeProofLog(run: $run, data: $this->proofLogData(run: $run, deliveryStatus: $deliveryStatus));

		if ($submission === null) {
			$this->logger->warning(
				'Portaliq: WMEBV proof-log write failed — retrying with a minimal fallback record',
				['appId' => $run->appId, 'actionId' => $run->actionId, 'subjectRef' => $run->subjectRef]
			);
			$this->writeFallbackSubmission(run: $run);
		}
	}//end doRecord()

	/**
	 * The receipt message, in the portal's language only, as change notices
	 * are (REQ-NAP-010).
	 *
	 * @param ReceiptRun $run The receipt, with its reference and moment.
	 *
	 * @return array<string, mixed>
	 */
	private function receiptMessageData(ReceiptRun $run): array {
		$l10n = $this->language->forOrganisation(organisation: $run->organisation);
		return [
			'subject' => $l10n->t(self::SUBJECT_KEY, [$run->referenceId]),
			'body' => $l10n->t(self::BODY_KEY, [$run->referenceId, $this->readable(l10n: $l10n, moment: $run->submittedAt)]),
			'referenceId' => $run->referenceId,
			'dataCopy' => $run->whitelistedData,
			'read' => false,
			'receivedAt' => $run->submittedAt,
		];
	}//end receiptMessageData()

	/**
	 * The proof-log record linked to the receipt.
	 *
	 * @param ReceiptRun $run            The receipt, with its reference and moment.
	 * @param string     $deliveryStatus Whether the receipt message landed.
	 *
	 * @return array<string, mixed>
	 */
	private function proofLogData(ReceiptRun $run, string $deliveryStatus): array {
		return [
			'appId' => $run->appId,
			'actionId' => $run->actionId,
			'payloadCopy' => $run->whitelistedData,
			'receiptMessageRef' => $run->referenceId,
			'submittedAt' => $run->submittedAt,
			'deliveryStatus' => $deliveryStatus,
		] + $run->named;
	}//end proofLogData()

	/**
	 * Write the receipt message in the subject's inbox.
	 *
	 * @param ReceiptRun           $run  The receipt.
	 * @param array<string, mixed> $data The message.
	 *
	 * @return array<string, mixed>|null The written message, or null when the write failed.
	 */
	private function writeReceiptMessage(ReceiptRun $run, array $data): ?array {
		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::MESSAGE_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $run->subjectRef,
			organisation: $run->organisation,
			data: $data
		);
	}//end writeReceiptMessage()

	/**
	 * Write a proof-log record for the subject.
	 *
	 * @param ReceiptRun           $run  The receipt.
	 * @param array<string, mixed> $data The record.
	 *
	 * @return array<string, mixed>|null The written record, or null when the write failed.
	 */
	private function writeProofLog(ReceiptRun $run, array $data): ?array {
		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SUBMISSION_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $run->subjectRef,
			organisation: $run->organisation,
			data: $data
		);
	}//end writeProofLog()

	/**
	 * Resolve the proof-log `deliveryStatus` for a receipt-message write, and
	 * announce a successful one.
	 *
	 * On a write, fires the portal-notifications-dispatch `message.created`
	 * trigger. That call is fail-safe by construction
	 * (`NotificationDispatchService::dispatch` never throws), so it can never
	 * affect the WMEBV receipt/proof-log outcome. On a failed write, the gap is
	 * logged — the submission itself remains authoritative either way.
	 *
	 * @param array<string, mixed>|null $message The written receipt message, or null when the write failed.
	 * @param ReceiptRun                $run     The receipt.
	 *
	 * @return string Either `delivered` or `failed`.
	 */
	private function announceReceipt(?array $message, ReceiptRun $run): string {
		if ($message === null) {
			$this->logger->warning(
				'Portaliq: WMEBV receipt message write failed — submission remains authoritative',
				['appId' => $run->appId, 'actionId' => $run->actionId, 'subjectRef' => $run->subjectRef]
			);

			return 'failed';
		}

		$this->dispatchMessageCreated(run: $run);

		return 'delivered';
	}//end announceReceipt()

	/**
	 * Fire the `message.created` notification trigger for the subject.
	 *
	 * @param ReceiptRun $run The receipt.
	 *
	 * @return void
	 */
	private function dispatchMessageCreated(ReceiptRun $run): void {
		$this->notificationDispatch->dispatch(
			ruleKey: NotificationDispatchService::RULE_MESSAGE_CREATED,
			appId: $run->appId,
			subject: [
				'subjectRef' => $run->subjectRef,
				'organisation' => $run->organisation,
				'audience' => $run->audience,
			]
		);
	}//end dispatchMessageCreated()

	/**
	 * Best-effort minimal `portalSubmission` row when the full write failed —
	 * so the burden-of-proof log carries SOMETHING retriable rather than a
	 * silent gap. Itself guarded: a failure here is logged and swallowed, it
	 * can never propagate (the domain create already succeeded long ago).
	 *
	 * @param ReceiptRun $run The receipt.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#a-receipt-or-log-failure-never-loses-the-submission
	 */
	private function writeFallbackSubmission(ReceiptRun $run): void {
		try {
			$this->writeProofLog(run: $run, data: $this->fallbackProofLogData(run: $run));
		} catch (Throwable $e) {
			$this->logger->error(
				'Portaliq: WMEBV fallback proof-log write also failed',
				['appId' => $run->appId, 'actionId' => $run->actionId, 'reason' => $e->getMessage()]
			);
		}
	}//end writeFallbackSubmission()

	/**
	 * The minimal proof-log record: who, what, now, and that it failed.
	 *
	 * @param ReceiptRun $run The receipt.
	 *
	 * @return array<string, mixed>
	 */
	private function fallbackProofLogData(ReceiptRun $run): array {
		return [
			'appId' => $run->appId,
			'actionId' => $run->actionId,
			'submittedAt' => $this->now(),
			'deliveryStatus' => 'failed',
		] + $run->named;
	}//end fallbackProofLogData()

	/**
	 * A short, unique receipt reference id (not a secret — merely an
	 * evidentiary correlation key shown to the subject and linked from the
	 * proof log). Generated up front so it can be embedded in the receipt's
	 * own body text at write time.
	 *
	 * @return string
	 */
	private function generateReferenceId(): string {
		try {
			$random = bin2hex(random_bytes(8));
		} catch (Throwable) {
			// Randomness generation failing is exceptionally rare; degrade to a
			// still-unique-enough fallback rather than block the receipt.
			$random = str_pad((string)mt_rand(0, PHP_INT_MAX), 16, '0', STR_PAD_LEFT);
		}

		return 'WMEBV-' . strtoupper($random);
	}//end generateReferenceId()

	/**
	 * A moment as the reader reads it: the day in words and the time to the
	 * minute, in the portal's language and the instance's time zone
	 * ("16 oktober 2026 09:41"). The stored value stays ISO.
	 *
	 * @param IL10N  $l10n   The portal's language.
	 * @param string $moment An ISO 8601 moment.
	 *
	 * @return string The readable moment, or the moment as given when it cannot be read.
	 *
	 * @spec openspec/changes/receipt-date-reads-in-the-portals-language/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-names-the-moment-in-words-of-the-portals-language
	 */
	private function readable(IL10N $l10n, string $moment): string {
		try {
			$zone = ($this->timeZone?->getTimeZone() ?? new DateTimeZone('UTC'));
			$text = $l10n->l('datetime', (new DateTimeImmutable($moment))->setTimezone($zone), ['width' => 'long|short']);
		} catch (Throwable) {
			return $moment;
		}

		if (is_string($text) === false || $text === '') {
			return $moment;
		}

		return $text;
	}//end readable()

	/**
	 * The current time as an ISO-8601 string (testable via ITimeFactory).
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate('c', $this->timeFactory->getTime());
	}//end now()

	/**
	 * The WMEBV "copy of the submitted data" for a completion — the role the
	 * whitelisted field map plays for a create. It carries what the AUTHORITY
	 * RECORDED — the seam row's `responses`, `evidence` (file NAMES as stored)
	 * and outcome, plus the resident's comment — falling back to the request
	 * only where the seam row carries no such key. Never file content, never a
	 * temp path: this copy lands in the resident's receipt (`dataCopy`) and in
	 * the proof log (`payloadCopy`).
	 *
	 * @param string $taskUuid The completed task's uuid.
	 * @param array<string, mixed> $task The seam's completed task row.
	 * @param array<string, mixed> $answers The submitted answers.
	 * @param string|null $comment The resident's comment.
	 * @param string $outcome The requested outcome.
	 * @param array<int, array<string, mixed>> $files The relayed uploads.
	 *
	 * @return array<string, mixed> The whitelisted copy for the receipt and proof log.
	 *
	 * A pure mapping of the seam's answer: it reads no service state, so a
	 * test can exercise it on a partially mocked service.
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
	 */
	public function taskCompletionCopy(
		string $taskUuid,
		array $task,
		array $answers,
		?string $comment,
		string $outcome,
		array $files,
	): array {
		// 🔴 THE COPY DESCRIBES WHAT THE AUTHORITY RECORDED, NOT WHAT THE RESIDENT
		// SENT. The seam's completed row is authoritative: `evidence` lists the
		// files PortalTaskService::storeFiles() actually wrote to the case
		// (PHP drops an oversized/partial upload with tmp_name '' and the
		// gateway then forwards nothing for it, while the request still names
		// it), `responses` is the answers map the seam stored. A receipt or
		// proof log naming an upload the authority never received would be a
		// false art. 2:10 statement (review of #501). The request is only the
		// fallback for a seam row that predates those keys.
		$names           = $this->recordedFileNames(task: $task, files: $files);
		$recordedAnswers = $this->recordedAnswers(task: $task, answers: $answers);
		$recorded        = $this->recordedOutcome(task: $task, outcome: $outcome);

		return [
			'taskUuid' => $taskUuid,
			// What the resident saw in "Mijn taken" (displayTitle), falling back
			// to the raw title for a seam that does not compute one.
			'title' => (string)($task['displayTitle'] ?? $task['title'] ?? ''),
			'outcome' => $recorded,
			'comment' => (string)($comment ?? ''),
			'answers' => $recordedAnswers,
			'files' => $names,
		];
	}//end taskCompletionCopy()

	/**
	 * The names of the files the seam recorded as `evidence`, or of the
	 * relayed uploads for a seam row that predates that key; an unnamed one
	 * is labelled `upload` rather than dropped.
	 *
	 * @param array<string, mixed>             $task  The seam's completed task row.
	 * @param array<int, array<string, mixed>> $files The relayed uploads.
	 *
	 * @return array<int, string>
	 */
	private function recordedFileNames(array $task, array $files): array {
		$recordedFiles = $files;
		if (array_key_exists('evidence', $task) === true) {
			$recordedFiles = (array)$task['evidence'];
		}

		$names = [];
		foreach ($recordedFiles as $stored) {
			$names[] = $this->storedFileName(stored: $stored);
		}

		return $names;
	}//end recordedFileNames()

	/**
	 * The name of one recorded file, or `upload` when it carries none.
	 *
	 * @param mixed $stored One recorded file.
	 *
	 * @return string
	 */
	private function storedFileName(mixed $stored): string {
		if (is_array($stored) === true) {
			return (string)($stored['name'] ?? 'upload');
		}

		return 'upload';
	}//end storedFileName()

	/**
	 * The answers map the seam stored, or the submitted answers when it
	 * stored none.
	 *
	 * @param array<string, mixed> $task    The seam's completed task row.
	 * @param array<string, mixed> $answers The submitted answers.
	 *
	 * @return array<string, mixed>
	 */
	private function recordedAnswers(array $task, array $answers): array {
		if (is_array($task['responses'] ?? null) === true) {
			return $task['responses'];
		}

		return $answers;
	}//end recordedAnswers()

	/**
	 * The outcome the seam stored, or the requested one when it stored none.
	 *
	 * @param array<string, mixed> $task    The seam's completed task row.
	 * @param string               $outcome The requested outcome.
	 *
	 * @return string
	 */
	private function recordedOutcome(array $task, string $outcome): string {
		$recorded = (string)($task['outcome'] ?? '');
		if ($recorded === '') {
			return $outcome;
		}

		return $recorded;
	}//end recordedOutcome()

}//end class
