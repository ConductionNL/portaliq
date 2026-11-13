<?php

/**
 * Portaliq Notification Dispatch Job
 *
 * The async half of portal-notifications-dispatch: NotificationDispatchService
 * enqueues this job (never sending inline), and here — off the request path —
 * the subject's `portalAccount` is resolved, a privacy-minimal NL/EN B1 email is
 * rendered (organisation name + a deep link only, NEVER case content, per
 * design.md), and sent via `OCP\Mail\IMailer`. Every attempt writes a NEW
 * append-only `portalNotification` row (accountRef, ruleKey, channel, status,
 * attempts, lastAttemptAt) — never updated in place, mirroring the WMEBV
 * burden-of-proof append-only convention `portalSubmission` already uses. After
 * N consecutive failures for the account the `portalAccount` is flagged
 * `needsAlternativeContact` (the WMEBV notificatieplicht ~Awb 2:11 fallback
 * signal); a subsequent successful send clears the flag again.
 *
 * A subject whose `portalAccount.email` is unset is never sent to — the
 * attempt is still logged (`status: failed`) so it counts toward the fallback
 * threshold, never silently dropped (the Nationale ombudsman's 17%-missing-
 * notification-email finding, 2017/098, is exactly this failure mode).
 *
 * Every failure — a missing account, OpenRegister unavailable, IMailer
 * throwing — is caught and logged; the job never lets an exception escape to
 * the NC cron runner.
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
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/specs/supplier-portal/spec.md#every-dispatch-attempt-is-logged
 * @spec openspec/specs/supplier-portal/spec.md#repeated-failure-flags-an-alternative-contact-fallback
 */

declare(strict_types=1);

namespace OCA\Portaliq\BackgroundJob;

use OCA\Portaliq\Kernel\Contracts\NotificationChannelsInterface;
use OCA\Portaliq\Kernel\Contracts\PortalDeepLinkBuilderInterface;
use OCA\Portaliq\Kernel\Contracts\PortalNoticeLanguageInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Kernel\Contracts\PortalOrganisationConfigServiceInterface;
use OCA\Portaliq\Service\Notifications\NotificationChannels;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IConfig;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the subject's account, sends the privacy-minimal email, and logs
 * the outcome — the async, side-effect-only half of notification dispatch.
 *
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the constructor wires
 * every dependency this job's fully self-contained pipeline (account lookup →
 * render → send → log → fallback flag) needs; splitting further would scatter
 * one cohesive background job across classes for no safety benefit.
 */
class NotificationDispatchJob extends QueuedJob {
	/**
	 * The register every portal-owned schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	private const ACCOUNT_SCHEMA = 'portalAccount';

	/**
	 * The status of a withdrawn account (PortalAccountService::STATUS_VOID).
	 */
	private const STATUS_VOID = 'void';

	/**
	 * The append-only per-attempt notification log schema.
	 */
	private const NOTIFICATION_SCHEMA = 'portalNotification';

	/**
	 * This app's id, for its own configuration.
	 *
	 * @var string
	 */
	private const APP_ID = 'portaliq';

	/**
	 * A change rule's subject line, `%1$s` the organisation.
	 *
	 * @var string
	 */
	private const CHANGE_SUBJECT_KEY = 'Something changed in the portal of %1$s';

	/**
	 * A change rule's body: `%1$s` the collection label, `%2$s` the
	 * organisation, `%3$s` the link to the record. No field value, ever
	 * (REQ-NAP-006).
	 *
	 * @var string
	 */
	private const CHANGE_BODY_KEY = 'Something changed on your %1$s in the portal of %2$s. Sign in to see it: %3$s';

	/**
	 * App-config key for the configurable consecutive-failure threshold.
	 */
	private const THRESHOLD_CONFIG_KEY = 'notification_failure_threshold';

	/**
	 * Default consecutive-failure threshold before flagging
	 * `needsAlternativeContact` (WMEBV notificatieplicht fallback) — small by
	 * design so the fallback signal fires promptly.
	 */
	private const DEFAULT_THRESHOLD = 3;

	/**
	 * The subject-line / body translation keys (B1 language level, English key
	 * text per convention — `l10n/nl.json` supplies the Dutch translation;
	 * `l10n/en.json` maps it to itself). Deliberately generic: it MUST NOT name
	 * the triggering app, rule key, or any case content.
	 */
	private const SUBJECT_KEY = 'You have a new message in the portal of %1$s';

	private const BODY_KEY = 'You have a new message in the portal of %1$s. Log in to view it: %2$s';

	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Testable clock (Job base class).
	 * @param ScopedCollectionReaderInterface $reader Resolves the subject's own portalAccount.
	 * @param PortalObjectWriterInterface $writer Writes the portalNotification log + the
	 *                                   needsAlternativeContact flag.
	 * @param PortalOrganisationConfigServiceInterface $orgConfig Resolves the tenant's display name.
	 * @param IMailer $mailer Sends the privacy-minimal email.
	 * @param PortalNoticeLanguageInterface $language The translations in the language of the resident's portal.
	 * @param PortalDeepLinkBuilderInterface $deepLinks Builds the portal deep link from the route table (WOO-570).
	 * @param IConfig $config Reads the configurable failure threshold.
	 * @param LoggerInterface $logger The logger.
	 * @param NotificationChannelsInterface|null $channels The resident's per-kind choices and the push.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriterInterface $writer,
		private readonly PortalOrganisationConfigServiceInterface $orgConfig,
		private readonly IMailer $mailer,
		private readonly PortalNoticeLanguageInterface $language,
		private readonly PortalDeepLinkBuilderInterface $deepLinks,
		private readonly IConfig $config,
		private readonly LoggerInterface $logger,
		private readonly ?NotificationChannelsInterface $channels = null,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run one queued dispatch attempt. Never throws — every failure mode is
	 * caught and logged so the NC cron runner is never disrupted.
	 *
	 * @param mixed $argument Expects `{subjectRef, organisation, audience, appId, ruleKey}`
	 *                        (NotificationDispatchService's content-free payload).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
	 */
	protected function run($argument): void {
		try {
			$this->doRun(argument: $argument);
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: NotificationDispatchJob failed entirely', ['reason' => $e->getMessage()]);
		}
	}//end run()

	/**
	 * The actual dispatch pipeline, isolated so run() can wrap it in one
	 * fail-safe try/catch: the fail-closed guards (missing argument, no
	 * account), then the dispatch on the channels the subject wants.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return void
	 */
	private function doRun(mixed $argument): void {
		$run = $this->runFrom(argument: $argument);
		if ($run === null) {
			return;
		}

		$account = $this->findAccount(subjectRef: $run->subjectRef, organisation: $run->organisation);
		if ($account === null) {
			// No account resolvable — nothing to notify and nothing owned to
			// log against; a normal state when the account has since been
			// removed, never an error.
			$this->logger->debug('Portaliq: NotificationDispatchJob found no portalAccount — skipping', ['subjectRef' => $run->subjectRef]);
			return;
		}

		$run->account = $account;
		$this->dispatch(run: $run);
	}//end doRun()

	/**
	 * The run for a valid job argument, or null (logged) for an argument that
	 * is not an array or lacks the subject or the rule key.
	 *
	 * @param mixed $argument The job argument.
	 *
	 * @return NotificationDispatchRun|null
	 */
	private function runFrom(mixed $argument): ?NotificationDispatchRun {
		if (is_array($argument) === false) {
			$this->logger->warning('Portaliq: NotificationDispatchJob invalid argument — not an array');
			return null;
		}

		$run = new NotificationDispatchRun(
			argument: $argument,
			subjectRef: (string)($argument['subjectRef'] ?? ''),
			organisation: (string)($argument['organisation'] ?? ''),
			ruleKey: (string)($argument['ruleKey'] ?? ''),
			appId: (string)($argument['appId'] ?? ''),
			channels: ($this->channels ?? new NotificationChannels())
		);
		if ($run->subjectRef === '' || $run->ruleKey === '') {
			$this->logger->warning('Portaliq: NotificationDispatchJob missing required argument keys');
			return null;
		}

		return $run;
	}//end runFrom()

	/**
	 * Send on each channel the subject wants, once the account has an id.
	 *
	 * @param NotificationDispatchRun $run The dispatch, its account found.
	 *
	 * @return void
	 */
	private function dispatch(NotificationDispatchRun $run): void {
		$this->chooseChannels(run: $run);
		$accountId = $this->rowId(row: $run->account);
		if ($accountId === null) {
			return;
		}

		$run->accountId = $accountId;
		if ($run->wantsPush === true) {
			$this->sendPush(run: $run);
		}

		if ($run->wantsEmail === true) {
			$this->emailAndRecord(run: $run);
		}
	}//end dispatch()

	/**
	 * Read the record a change rule is about and the channels the subject
	 * wants for its kind.
	 *
	 * @param NotificationDispatchRun $run The dispatch, its account found.
	 *
	 * @return void
	 */
	private function chooseChannels(NotificationDispatchRun $run): void {
		$run->record = $run->channels->record(argument: $run->argument);
		$kind        = $run->channels->kindOf(record: $run->record);

		// A channel the subject switched off is neither a missing declaration
		// nor a delivery failure: nothing is sent and nothing is logged for it,
		// never the "no email address" shape (which counts toward
		// needsAlternativeContact). The account-wide e-mail opt-out
		// (notification-preferences-per-role) still wins over a kind's choice.
		$run->wantsEmail = $run->channels->wants(account: $run->account, kind: $kind, channel: NotificationChannelsInterface::CHANNEL_EMAIL);
		$run->wantsPush  = $run->channels->wants(account: $run->account, kind: $kind, channel: NotificationChannelsInterface::CHANNEL_PUSH);
	}//end chooseChannels()

	/**
	 * Send the e-mail, log the attempt, and keep the needsAlternativeContact
	 * flag in step with the failure streak.
	 *
	 * @param NotificationDispatchRun $run The dispatch, its account id read.
	 *
	 * @return void
	 */
	private function emailAndRecord(NotificationDispatchRun $run): void {
		$email          = (string)($run->account['email'] ?? '');
		$previousStreak = $this->previousFailureStreak(run: $run);

		$sent = false;
		if ($email !== '' && $this->mailer->validateMailAddress($email) === true) {
			$sent = $this->sendEmail(run: $run, email: $email);
		}

		$outcome = $run->emailOutcome(sent: $sent, previousStreak: $previousStreak);
		$this->recordAttempt(run: $run, outcome: $outcome);
		$this->keepFallbackFlag(run: $run, outcome: $outcome);
	}//end emailAndRecord()

	/**
	 * Flag the account after the threshold of consecutive failures, and
	 * clear the flag after a send that went through.
	 *
	 * @param NotificationDispatchRun $run     The dispatch.
	 * @param NotificationOutcome     $outcome How the e-mail attempt ended.
	 *
	 * @return void
	 */
	private function keepFallbackFlag(NotificationDispatchRun $run, NotificationOutcome $outcome): void {
		$threshold = $this->failureThreshold();
		if ($outcome->isSent() === false && $outcome->attempts >= $threshold) {
			$this->flagNeedsAlternativeContact(run: $run, flag: true);
		} elseif ($outcome->isSent() === true && ($run->account['needsAlternativeContact'] ?? false) === true) {
			// A working notification just went through — the fallback signal
			// is no longer current; clear it so an operator's backlog reflects
			// reality.
			$this->flagNeedsAlternativeContact(run: $run, flag: false);
		}
	}//end keepFallbackFlag()

	/**
	 * Send the push when the resident registered a device, and log it with
	 * channel `push`. A push that did not arrive is not an address problem, so
	 * it never counts toward needsAlternativeContact.
	 *
	 * @param NotificationDispatchRun $run The dispatch, its account id read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	private function sendPush(NotificationDispatchRun $run): void {
		$run->organisationName = $this->organisationName(organisation: $run->organisation);
		$title         = $this->subjectLine(run: $run);
		$run->deepLink = $this->deepLinks->forNotice(organisation: $run->organisation, record: $run->record);
		$status        = $run->channels->push(subjectRef: $run->subjectRef, title: $title, body: $this->bodyText(run: $run));
		if ($status === null) {
			return;
		}

		$this->recordAttempt(run: $run, outcome: new NotificationOutcome(status: $status, attempts: 0, channel: NotificationChannelsInterface::CHANNEL_PUSH));
	}//end sendPush()

	/**
	 * Send the privacy-minimal email, in the portal's language.
	 *
	 * @param NotificationDispatchRun $run   The dispatch.
	 * @param string                  $email The validated recipient address.
	 *
	 * @return bool True on a successful send (no failed-recipients reported).
	 */
	private function sendEmail(NotificationDispatchRun $run, string $email): bool {
		$run->organisationName = $this->organisationName(organisation: $run->organisation);
		// The deep link into the site, resolved from the route table (WOO-570);
		// for a change rule it opens that record (REQ-NAP-005).
		$run->deepLink         = $this->deepLinks->forNotice(organisation: $run->organisation, record: $run->record);

		try {
			$failedRecipients = $this->sendNotice(run: $run, email: $email);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: notification email send failed', ['reason' => $e->getMessage()]);
			return false;
		}

		if (count($failedRecipients) > 0) {
			$this->logger->warning('Portaliq: notification email reported failed recipients');
			return false;
		}

		return true;
	}//end sendEmail()

	/**
	 * Write the notification message to one address and send it.
	 *
	 * @param NotificationDispatchRun $run   The dispatch, its words resolved.
	 * @param string                  $email The recipient.
	 *
	 * @return array<int, string> The recipients the mail server refused.
	 */
	private function sendNotice(NotificationDispatchRun $run, string $email): array {
		$message = $this->mailer->createMessage();
		$message->setSubject($this->subjectLine(run: $run));
		$message->setPlainBody($this->bodyText(run: $run));
		$message->setTo([$email]);

		return $this->mailer->send($message);
	}//end sendNotice()

	/**
	 * The B1-level subject line, in the portal's language. Privacy-minimal
	 * by construction: the ONLY variable is the organisation display name.
	 *
	 * @param NotificationDispatchRun $run The dispatch: its tenant's portal picks the language.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-receipt-a-notification-mail-and-a-task-notice-are-written-in-the-portals-language-only
	 */
	private function subjectLine(NotificationDispatchRun $run): string {
		$key = [self::SUBJECT_KEY, self::CHANGE_SUBJECT_KEY][(int)($run->record !== [])];

		return $this->language->forOrganisation(organisation: $run->organisation)->t($key, [$run->organisationName]);
	}//end subjectLine()

	/**
	 * The B1-level body text, in the portal's language. Privacy-minimal
	 * by construction: the ONLY variables are the organisation display name and
	 * the deep link — never the message subject, body, case identifiers, or any
	 * data beyond the recipient address (design.md). Of a change rule's record
	 * only its collection label reaches the text (REQ-NAP-006).
	 *
	 * @param NotificationDispatchRun $run The dispatch, its name and deep link resolved.
	 *
	 * @return string
	 */
	private function bodyText(NotificationDispatchRun $run): string {
		$key        = self::BODY_KEY;
		$parameters = [$run->organisationName, $run->deepLink];
		if ($run->record !== []) {
			$key        = self::CHANGE_BODY_KEY;
			$parameters = [(string)($run->record['label'] ?? ''), $run->organisationName, $run->deepLink];
		}

		return $this->language->forOrganisation(organisation: $run->organisation)->t($key, $parameters);
	}//end bodyText()

	/**
	 * The tenant's display name.
	 *
	 * @param string $organisation The tenant slug.
	 *
	 * @return string
	 */
	private function organisationName(string $organisation): string {
		return (string)($this->orgConfig->resolve(orgSlug: $organisation)['organisationName'] ?? 'Portaliq');
	}//end organisationName()

	/**
	 * Resolve the subject's OWN portalAccount, scoped exactly like every other
	 * portal read (subjectRef + tenant), or null. The job payload also carries
	 * `audience` (for parity with the trigger's subject shape), but this
	 * lookup scopes by subjectRef alone, which is already globally unique.
	 *
	 * @param string $subjectRef The subject's own reference.
	 * @param string $organisation The subject's tenant (may be empty).
	 *
	 * @return array<string, mixed>|null
	 */
	private function findAccount(string $subjectRef, string $organisation): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::ACCOUNT_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			limit: 2
		);

		$account = ($rows[0] ?? null);
		if (($account['status'] ?? '') === self::STATUS_VOID) {
			$this->logger->info('Portaliq: NotificationDispatchJob skips a withdrawn portalAccount', ['account' => (string)$this->rowId(row: $account)]);
			return null;
		}

		return $account;
	}//end findAccount()

	/**
	 * The consecutive-failure streak going into THIS attempt: the `attempts`
	 * value of the most recent `portalNotification` row for this
	 * (accountId, ruleKey), or 0 when there is no prior history (or the most
	 * recent row was itself a success). Read-only — this row is never mutated,
	 * a new one is always appended.
	 *
	 * @param NotificationDispatchRun $run The dispatch, its account id read.
	 *
	 * @return int
	 */
	private function previousFailureStreak(NotificationDispatchRun $run): int {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::NOTIFICATION_SCHEMA,
			scopeField: 'accountRef',
			subjectRef: $run->accountId,
			organisation: $run->organisation,
			limit: 50
		);

		return $run->failureStreakIn(rows: $rows);
	}//end previousFailureStreak()

	/**
	 * Append a new `portalNotification` row for this attempt (never updated in
	 * place — an honest per-attempt log, mirroring the WMEBV burden-of-proof
	 * append-only convention `portalSubmission` already uses). The row is
	 * stamped with the account as `accountRef`; the subject is named in the
	 * warning only (audit trail, not the scope field).
	 *
	 * @param NotificationDispatchRun $run     The dispatch, its account id read.
	 * @param NotificationOutcome     $outcome How the attempt ended.
	 *
	 * @return void
	 */
	private function recordAttempt(NotificationDispatchRun $run, NotificationOutcome $outcome): void {
		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::NOTIFICATION_SCHEMA,
			scopeField: 'accountRef',
			subjectRef: $run->accountId,
			organisation: $run->organisation,
			data: $this->attemptRow(run: $run, outcome: $outcome)
		);

		if ($created === null) {
			$this->logger->warning(
				'Portaliq: portalNotification log write failed — the dispatch attempt itself already ran',
				['subjectRef' => $run->subjectRef, 'ruleKey' => $run->ruleKey, 'status' => $outcome->status]
			);
		}
	}//end recordAttempt()

	/**
	 * The `portalNotification` row of one attempt.
	 *
	 * @param NotificationDispatchRun $run     The dispatch.
	 * @param NotificationOutcome     $outcome How the attempt ended.
	 *
	 * @return array<string, mixed>
	 */
	private function attemptRow(NotificationDispatchRun $run, NotificationOutcome $outcome): array {
		return [
			'ruleKey' => $run->ruleKey,
			'appId' => $run->appId,
			'channel' => $outcome->channel,
			'status' => $outcome->status,
			'attempts' => $outcome->attempts,
			'lastAttemptAt' => gmdate('c'),
		];
	}//end attemptRow()

	/**
	 * Read the configurable consecutive-failure threshold, or the small
	 * default when unset/invalid (fail-safe — never zero, which would flag
	 * every single failure as an immediate fallback).
	 *
	 * @return int
	 */
	private function failureThreshold(): int {
		$raw = (int)$this->config->getAppValue(self::APP_ID, self::THRESHOLD_CONFIG_KEY, (string)self::DEFAULT_THRESHOLD);
		if ($raw < 1) {
			return self::DEFAULT_THRESHOLD;
		}

		return $raw;
	}//end failureThreshold()

	/**
	 * Set (or clear) the account's `needsAlternativeContact` fallback flag —
	 * the WMEBV notificatieplicht signal. Ownership is re-verified by the
	 * writer (scopeField `subjectRef` === the subject this account belongs to)
	 * before anything is written.
	 *
	 * @param NotificationDispatchRun $run  The dispatch, its account id read.
	 * @param bool                    $flag True to set, false to clear.
	 *
	 * @return void
	 */
	private function flagNeedsAlternativeContact(NotificationDispatchRun $run, bool $flag): void {
		$updated = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::ACCOUNT_SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $run->subjectRef,
			organisation: $run->organisation,
			id: $run->accountId,
			data: ['needsAlternativeContact' => $flag]
		);

		if ($updated === null) {
			$this->logger->warning(
				'Portaliq: failed to update portalAccount.needsAlternativeContact',
				['subjectRef' => $run->subjectRef, 'flag' => $flag]
			);
		}
	}//end flagNeedsAlternativeContact()

	/**
	 * Extract a row's identifier (`id`/`uuid`, flat or in `@self`), or null.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
		$self = ($row['@self'] ?? null);
		$selfUuid = null;
		$selfId = null;
		if (is_array($self) === true) {
			$selfUuid = ($self['uuid'] ?? null);
			$selfId = ($self['id'] ?? null);
		}

		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), $selfUuid, $selfId];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end rowId()
}//end class
