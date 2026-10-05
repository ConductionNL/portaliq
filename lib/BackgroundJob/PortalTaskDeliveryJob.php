<?php

/**
 * Portaliq Portal Task Delivery Job
 *
 * Portaliq's half of openregister's portal task delivery seam
 * (`feature/flow-portal-task`): the engine RECORDS an ask, re-ask or reminder
 * as one ledger row per channel and sends nothing; this recurring job drains
 * `PortalTaskDeliveryService::pending()` in-process (portaliq's normal shape
 * is co-installed, ADR-046 — the seam's REST admin surface stays the
 * documented fallback for a split deployment and is deliberately NOT consumed
 * here: two consumers of one ledger is double delivery).
 *
 * A `portal-inbox` row becomes ONE `portalMessage` in the party's own inbox
 * scope, carrying the task uuid (the deep link) and the ledger row's uuid as
 * `deliveryUuid` (the idempotency key: a crash between write and settle is
 * healed by finding that message and settling, never by writing a second).
 * A `mail` row becomes ONE privacy-minimal mail, in the portal's language, to the party's own
 * `portalAccount` address — organisation name and portal link only, never task
 * or case content, the NotificationDispatchJob posture. Every row settles as
 * `markDelivered()` or `markFailed(reason)`; each is processed in its own
 * try/catch, so one failing row never blocks the rest, and nothing ever
 * escapes to the cron runner. Absent openregister the job logs at debug and
 * does nothing.
 *
 * @category BackgroundJob
 * @package  OCA\Portaliq\BackgroundJob
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
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Portaliq\Service\Notifications\PortalNoticeLanguage;
use OCA\Portaliq\Service\PortalDeepLinkBuilder;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Drains the portal task delivery ledger into inbox messages and mails.
 *
 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the constructor wires
 * every dependency this job's self-contained pipeline (drain → render → write
 * message / send mail → settle) needs, the same rationale
 * NotificationDispatchJob documents.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the pipeline touches the
 * reader, writer, mailer, l10n, org config and the container-resolved ledger;
 * splitting it would scatter one cohesive worker across classes.
 */
class PortalTaskDeliveryJob extends TimedJob {
	/**
	 * The register every portal-owned schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The subject-inbox message schema (the SAME inbox portal-inbox-v2
	 * aggregates — reused, not duplicated).
	 */
	private const MESSAGE_SCHEMA = 'portalMessage';

	/**
	 * The account schema the mail address comes from.
	 */
	private const ACCOUNT_SCHEMA = 'portalAccount';

	/**
	 * OpenRegister's delivery ledger seam, resolved BY NAME at run time (the
	 * PortalObjectReader pattern) so portaliq keeps no compile-time
	 * dependency on openregister.
	 */
	private const DELIVERY_SERVICE = 'OCA\\OpenRegister\\Service\\Portal\\PortalTaskDeliveryService';

	/**
	 * The engine freezes the matched party as `party:<subjectRef>`.
	 */
	private const PARTY_PREFIX = 'party:';

	/**
	 * Ledger rows drained per run — a backlog drains oldest first across runs.
	 */
	private const BATCH_LIMIT = 50;

	/**
	 * Seconds between runs.
	 */
	private const INTERVAL = 300;

	/**
	 * Subject-line translation keys per delivery kind (B1, English source
	 * keys per convention — `l10n/nl.json` carries the Dutch).
	 */
	private const SUBJECT_KEYS = [
		'ask' => 'You have a new task: %1$s',
		're-ask' => 'Your task needs another look: %1$s',
		'reminder' => 'Reminder about your task: %1$s',
		'overdue' => 'Your task is overdue: %1$s',
	];

	/**
	 * Body-part translation keys (B1).
	 */
	private const BODY_DUE_KEY = 'Please finish this task before %1$s.';

	/**
	 * The due line once the date has passed: "finish before" would ask for
	 * the impossible (tasks-reminders-after-the-deadline D3).
	 */
	private const BODY_WAS_DUE_KEY = 'This task was due on %1$s.';

	private const BODY_CONSEQUENCE_KEY = 'If you do not respond: %1$s';

	private const BODY_REASON_KEY = 'The reason: %1$s';

	private const BODY_OPEN_KEY = 'Open "Mijn taken" in the portal to complete this task.';

	/**
	 * The privacy-minimal mail keys per delivery kind — organisation name and
	 * link ONLY, never task or case content (the NotificationDispatchJob
	 * posture). `ask` and `re-ask` announce a new task; a `reminder` is about
	 * a task the resident already has, so it must not call it new (#803,
	 * tasks-reminders-after-the-deadline D4), and an `overdue` notice says
	 * it is late. There is no fallback: settleRow() fails a row whose kind is
	 * not a key here (D2).
	 */
	private const MAIL_KEYS = [
		'ask' => [
			'subject' => 'You have a new task in the portal of %1$s',
			'body' => 'You have a new task in the portal of %1$s. Log in to view it: %2$s',
		],
		're-ask' => [
			'subject' => 'You have a new task in the portal of %1$s',
			'body' => 'You have a new task in the portal of %1$s. Log in to view it: %2$s',
		],
		'reminder' => [
			'subject' => 'Reminder: you have an open task in the portal of %1$s',
			'body' => 'You have an open task in the portal of %1$s. Log in to finish it: %2$s',
		],
		'overdue' => [
			'subject' => 'Your task in the portal of %1$s is overdue',
			'body' => 'A task in the portal of %1$s is past its deadline. Log in to finish it: %2$s',
		],
	];

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Testable clock (job base class).
	 * @param ContainerInterface $container Resolves the ledger seam by name.
	 * @param PortalObjectReader $reader The idempotency probe + account lookup.
	 * @param PortalObjectWriter $writer Writes the inbox message (scope stamped server-side).
	 * @param PortalOrganisationConfigService $orgConfig Resolves the tenant display name.
	 * @param IMailer $mailer Sends the privacy-minimal mail.
	 * @param PortalNoticeLanguage $language The translations in the language of the resident's portal.
	 * @param PortalDeepLinkBuilder $deepLinks Builds the portal deep link from the route table (WOO-570).
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ContainerInterface $container,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalOrganisationConfigService $orgConfig,
		private readonly IMailer $mailer,
		private readonly PortalNoticeLanguage $language,
		private readonly PortalDeepLinkBuilder $deepLinks,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: self::INTERVAL);
		$this->setTimeSensitivity(sensitivity: IJob::TIME_INSENSITIVE);
	}//end __construct()

	/**
	 * Drain the ledger: settle every pending row, failures isolated per row.
	 *
	 * @param mixed $argument Unused — the TimedJob contract requires the
	 *                        parameter; a recurring drain carries no payload.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) -- the base class dictates
	 * the signature; dropping the parameter breaks the override.
	 */
	protected function run($argument): void {
		$ledger = $this->ledger();
		if ($ledger === null) {
			$this->logger->debug('[PortalTaskDeliveryJob] OpenRegister is not available — nothing to drain');
			return;
		}

		try {
			$rows = $ledger->pending(self::BATCH_LIMIT);
		} catch (Throwable $failure) {
			$this->logger->warning('[PortalTaskDeliveryJob] Could not read the pending deliveries: ' . $failure->getMessage());
			return;
		}

		foreach ($rows as $row) {
			if (is_object($row) === false) {
				continue;
			}

			$this->settleRow(ledger: $ledger, row: $row);
		}
	}//end run()

	/**
	 * Process ONE ledger row and settle it — every failure inside is caught,
	 * reported through markFailed where possible, and never propagated, so
	 * the next row always runs.
	 *
	 * @param object $ledger The resolved delivery ledger seam.
	 * @param object $row One pending PortalTaskDelivery row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-an-unknown-kind-fails-honestly-req-trd-003
	 */
	private function settleRow(object $ledger, object $row): void {
		$uuid = '';
		try {
			$uuid = (string)$row->getUuid();
			$party = (string)$row->getPartyReference();
			if (str_starts_with($party, self::PARTY_PREFIX) === false) {
				$this->markFailed(ledger: $ledger, uuid: $uuid, reason: 'unusable party reference (expected party:<subjectRef>)');
				return;
			}

			// A kind this job does not know would otherwise reach the
			// resident as a new task (tasks-reminders-after-the-deadline D2).
			$kind = (string)$row->getKind();
			if (isset(self::SUBJECT_KEYS[$kind], self::MAIL_KEYS[$kind]) === false) {
				$this->markFailed(ledger: $ledger, uuid: $uuid, reason: 'unknown delivery kind: ' . $kind);
				return;
			}

			$subjectRef = substr($party, strlen(self::PARTY_PREFIX));
			$channel = (string)$row->getChannel();
			$message = ($row->getMessage() ?? []);
			if (is_array($message) === false) {
				$message = [];
			}

			$error = match ($channel) {
				'portal-inbox' => $this->deliverInbox(uuid: $uuid, subjectRef: $subjectRef, kind: $kind, message: $message),
				'mail' => $this->deliverMail(subjectRef: $subjectRef, kind: $kind),
				default => 'unknown delivery channel: ' . $channel,
			};

			if ($error === null) {
				$ledger->markDelivered($uuid);
				return;
			}

			$this->markFailed(ledger: $ledger, uuid: $uuid, reason: $error);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[PortalTaskDeliveryJob] Delivery row failed: ' . $failure->getMessage(),
				['delivery' => $uuid]
			);
			$this->markFailed(ledger: $ledger, uuid: $uuid, reason: $failure->getMessage());
		}//end try
	}//end settleRow()

	/**
	 * Write the party's inbox message for one `portal-inbox` row, idempotently.
	 *
	 * @param string $uuid The ledger row uuid (stamped as `deliveryUuid`).
	 * @param string $subjectRef The party's subject reference.
	 * @param string $kind `ask`, `re-ask`, `reminder` or `overdue`.
	 * @param array<string, mixed> $message The engine's rendered payload
	 *                                      (title, description, reason, dueAt,
	 *                                      taskUuid — descriptors, never case data).
	 *
	 * @return string|null Null on success, else the failure reason.
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-inbox-message-deep-links-to-the-task
	 */
	private function deliverInbox(string $uuid, string $subjectRef, string $kind, array $message): ?string {
		// Idempotency probe: an earlier run may have written the message and
		// crashed before settling. Settle, never duplicate.
		$existing = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::MESSAGE_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			limit: 1,
			filter: ['deliveryUuid' => $uuid]
		);
		if (count($existing) > 0) {
			return null;
		}

		$l10n = $this->language->forOrganisation(organisation: (string)($this->account(subjectRef: $subjectRef)['organisation'] ?? ''));
		$written = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::MESSAGE_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			data: [
				'subject' => $l10n->t(self::SUBJECT_KEYS[$kind], [(string)($message['title'] ?? '')]),
				'body' => $this->bodyText(kind: $kind, message: $message, l10n: $l10n),
				'read' => false,
				'receivedAt' => $this->time->getDateTime()->format(DateTimeInterface::ATOM),
				'taskUuid' => (string)($message['taskUuid'] ?? ''),
				'deliveryUuid' => $uuid,
			]
		);
		if ($written === null) {
			return 'portal message write failed';
		}

		return null;
	}//end deliverInbox()

	/**
	 * Send the privacy-minimal mail for one `mail` row.
	 *
	 * @param string $subjectRef The party's subject reference.
	 * @param string $kind       `ask`, `re-ask`, `reminder` or `overdue`; picks the subject and body.
	 *
	 * @return string|null Null on success, else the failure reason.
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-the-mail-says-which-kind-of-delivery-it-is-req-trd-002
	 */
	private function deliverMail(string $subjectRef, string $kind): ?string {
		$account = $this->account(subjectRef: $subjectRef);
		if ($account === null) {
			return 'no portalAccount for the party';
		}

		$email = (string)($account['email'] ?? '');
		if ($email === '' || $this->mailer->validateMailAddress($email) === false) {
			return 'the portalAccount has no valid mail address';
		}

		$organisation = (string)($account['organisation'] ?? '');
		$organisationName = (string)($this->orgConfig->resolve(orgSlug: $organisation)['organisationName'] ?? 'Portaliq');
		$deepLink = $this->deepLink(organisation: $organisation);

		$keys = self::MAIL_KEYS[$kind];
		$l10n = $this->language->forOrganisation(organisation: $organisation);

		try {
			$mail = $this->mailer->createMessage();
			$mail->setSubject($l10n->t($keys['subject'], [$organisationName]));
			$mail->setPlainBody($l10n->t($keys['body'], [$organisationName, $deepLink]));
			$mail->setTo([$email]);
			$failedRecipients = $this->mailer->send($mail);
		} catch (Throwable $failure) {
			return 'mail send failed: ' . $failure->getMessage();
		}

		if (count($failedRecipients) > 0) {
			return 'mail reported failed recipients';
		}

		return null;
	}//end deliverMail()

	/**
	 * The B1 inbox body: the engine's description, the due date, the re-ask
	 * reason when present, and where to act, in the portal's language. An
	 * `overdue` notice says when the task was due and, when the engine sends
	 * one, what happens without a response.
	 *
	 * @param string $kind The delivery kind.
	 * @param array<string, mixed> $message The engine's payload.
	 * @param IL10N $l10n The translations in the portal's language.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-task-delivery/spec.md#requirement-an-overdue-delivery-reaches-the-resident-as-overdue-req-trd-001
	 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-a-notification-mail-and-a-task-notice-are-written-in-the-portals-language-only
	 */
	private function bodyText(string $kind, array $message, IL10N $l10n): string {
		$parts = [];

		$description = trim((string)($message['description'] ?? ''));
		if ($description !== '') {
			$parts[] = $description;
		}

		$reason = trim((string)($message['reason'] ?? ''));
		if ($reason !== '') {
			$parts[] = $l10n->t(self::BODY_REASON_KEY, [$reason]);
		}

		$due = $this->dueDate(raw: (string)($message['dueAt'] ?? ''));
		$overdue = ($kind === 'overdue');
		if ($due !== '') {
			$dueKey = self::BODY_DUE_KEY;
			if ($overdue === true) {
				$dueKey = self::BODY_WAS_DUE_KEY;
			}

			$parts[] = $l10n->t($dueKey, [$due]);
		}

		$consequence = trim((string)($message['consequence'] ?? ''));
		if ($overdue === true && $consequence !== '') {
			$parts[] = $l10n->t(self::BODY_CONSEQUENCE_KEY, [$consequence]);
		}

		$parts[] = $l10n->t(self::BODY_OPEN_KEY, []);

		return implode("\n\n", $parts);
	}//end bodyText()

	/**
	 * The party's own portal account, or null when they have none.
	 *
	 * @param string $subjectRef The party's subject reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function account(string $subjectRef): ?array {
		return ($this->reader->readCollection(
			register: self::REGISTER,
			schema: self::ACCOUNT_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			limit: 2
		)[0] ?? null);
	}//end account()

	/**
	 * A readable day for an ISO-8601 due timestamp, or '' when unparseable.
	 *
	 * @param string $raw The engine's `dueAt` value.
	 *
	 * @return string
	 */
	private function dueDate(string $raw): string {
		if ($raw === '') {
			return '';
		}

		try {
			return (new DateTimeImmutable($raw))->format('d-m-Y');
		} catch (Throwable) {
			return '';
		}
	}//end dueDate()

	/**
	 * The portal deep link the mail carries (content stays behind the auth edge).
	 *
	 * Resolved from the route table by PortalDeepLinkBuilder: the former
	 * `getAbsoluteURL('/portal')` pointed at a path no deployment serves, so the
	 * only call-to-action in the mail was a 404 (WOO-570).
	 *
	 * @param string $organisation The tenant slug ('' = the bare portal).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-task-delivery/specs/portal-task-delivery/spec.md#requirement-the-delivery-worker-settles-every-ledger-row-idempotently-and-in-isolation
	 */
	private function deepLink(string $organisation): string {
		return $this->deepLinks->forOrganisation(organisation: $organisation);
	}//end deepLink()

	/**
	 * Settle a row as failed, best-effort — a settle failure is logged, never
	 * thrown (the row simply stays pending for the next run).
	 *
	 * @param object $ledger The resolved ledger seam.
	 * @param string $uuid The delivery uuid ('' when even that was unreadable).
	 * @param string $reason Why the delivery failed.
	 *
	 * @return void
	 */
	private function markFailed(object $ledger, string $uuid, string $reason): void {
		if ($uuid === '') {
			return;
		}

		try {
			$ledger->markFailed($uuid, $reason);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[PortalTaskDeliveryJob] Could not settle a failed delivery: ' . $failure->getMessage(),
				['delivery' => $uuid, 'reason' => $reason]
			);
		}
	}//end markFailed()

	/**
	 * Resolve openregister's delivery ledger seam, or null when openregister
	 * is not installed (the job then does nothing — the REST fallback is a
	 * split-deployment concern, documented in design.md, not consumed here).
	 *
	 * @return object|null
	 */
	private function ledger(): ?object {
		try {
			$service = $this->container->get(self::DELIVERY_SERVICE);
		} catch (Throwable) {
			return null;
		}

		if (is_object($service) === true) {
			return $service;
		}

		return null;
	}//end ledger()
}//end class
