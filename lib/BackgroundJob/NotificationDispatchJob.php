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

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
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
class NotificationDispatchJob extends QueuedJob
{
    /**
     * The register every portal-owned schema lives in.
     */
    private const REGISTER = 'portaliq';

    /**
     * The account schema.
     */
    private const ACCOUNT_SCHEMA = 'portalAccount';

    /**
     * The append-only per-attempt notification log schema.
     */
    private const NOTIFICATION_SCHEMA = 'portalNotification';

    /**
     * The only channel implemented — future-proofed field, single value today.
     */
    private const CHANNEL_EMAIL = 'email';

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
     * @param ITimeFactory                    $time         Testable clock (Job base class).
     * @param PortalObjectReader              $reader       Resolves the subject's own portalAccount.
     * @param PortalObjectWriter              $writer       Writes the portalNotification log + the
     *                                                      needsAlternativeContact flag.
     * @param PortalOrganisationConfigService $orgConfig    Resolves the tenant's display name.
     * @param IMailer                         $mailer       Sends the privacy-minimal email.
     * @param IFactory                        $l10nFactory  Resolves NL/EN translators, independent
     *                                                      of any session locale.
     * @param IURLGenerator                   $urlGenerator Builds the portal deep link.
     * @param IConfig                         $config       Reads the configurable failure threshold.
     * @param LoggerInterface                 $logger       The logger.
     */
    public function __construct(
        ITimeFactory $time,
        private readonly PortalObjectReader $reader,
        private readonly PortalObjectWriter $writer,
        private readonly PortalOrganisationConfigService $orgConfig,
        private readonly IMailer $mailer,
        private readonly IFactory $l10nFactory,
        private readonly IURLGenerator $urlGenerator,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
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
    protected function run($argument): void
    {
        try {
            $this->doRun(argument: $argument);
        } catch (Throwable $e) {
            $this->logger->error('Portaliq: NotificationDispatchJob failed entirely', ['reason' => $e->getMessage()]);
        }
    }//end run()

    /**
     * The actual dispatch pipeline, isolated so run() can wrap it in one
     * fail-safe try/catch.
     *
     * @param mixed $argument The job argument.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) -- the complexity is a
     * sequence of fail-closed guard clauses (missing argument, no account,
     * no id) each returning early, plus the sent/failed branch — the SAME
     * fail-closed-guard rationale ContributionController's class docblock
     * documents; collapsing them would trade auditability for a score.
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    private function doRun(mixed $argument): void
    {
        if (is_array($argument) === false) {
            $this->logger->warning('Portaliq: NotificationDispatchJob invalid argument — not an array');
            return;
        }

        $subjectRef   = (string) ($argument['subjectRef'] ?? '');
        $organisation = (string) ($argument['organisation'] ?? '');
        $ruleKey      = (string) ($argument['ruleKey'] ?? '');

        if ($subjectRef === '' || $ruleKey === '') {
            $this->logger->warning('Portaliq: NotificationDispatchJob missing required argument keys');
            return;
        }

        $account = $this->findAccount(subjectRef: $subjectRef, organisation: $organisation);
        if ($account === null) {
            // No account resolvable — nothing to notify and nothing owned to
            // log against; a normal state when the account has since been
            // removed, never an error.
            $this->logger->debug('Portaliq: NotificationDispatchJob found no portalAccount — skipping', ['subjectRef' => $subjectRef]);
            return;
        }

        $accountId = $this->rowId(row: $account);
        if ($accountId === null) {
            return;
        }

        $email          = (string) ($account['email'] ?? '');
        $previousStreak = $this->previousFailureStreak(accountId: $accountId, organisation: $organisation, ruleKey: $ruleKey);

        $sent = false;
        if ($email !== '' && $this->mailer->validateMailAddress($email) === true) {
            $sent = $this->sendEmail(email: $email, organisation: $organisation);
        }

        $status   = 'failed';
        $attempts = ($previousStreak + 1);
        if ($sent === true) {
            $status   = 'sent';
            $attempts = 0;
        }

        $this->recordAttempt(
            accountId: $accountId,
            organisation: $organisation,
            ruleKey: $ruleKey,
            appId: (string) ($argument['appId'] ?? ''),
            subjectRef: $subjectRef,
            status: $status,
            attempts: $attempts
        );

        $threshold = $this->failureThreshold();
        if ($sent === false && $attempts >= $threshold) {
            $this->flagNeedsAlternativeContact(subjectRef: $subjectRef, organisation: $organisation, accountId: $accountId, flag: true);
        } else if ($sent === true && ($account['needsAlternativeContact'] ?? false) === true) {
            // A working notification just went through — the fallback signal
            // is no longer current; clear it so an operator's backlog reflects
            // reality.
            $this->flagNeedsAlternativeContact(subjectRef: $subjectRef, organisation: $organisation, accountId: $accountId, flag: false);
        }
    }//end doRun()

    /**
     * Send the privacy-minimal, bilingual (NL first, EN second) email.
     *
     * @param string $email        The validated recipient address.
     * @param string $organisation The subject's tenant (resolves the display name).
     *
     * @return bool True on a successful send (no failed-recipients reported).
     */
    private function sendEmail(string $email, string $organisation): bool
    {
        $organisationName = (string) ($this->orgConfig->resolve(orgSlug: $organisation)['organisationName'] ?? 'Portaliq');
        $deepLink         = $this->deepLink(organisation: $organisation);

        try {
            $message = $this->mailer->createMessage();
            $message->setSubject($this->subjectLine(organisationName: $organisationName));
            $message->setPlainBody($this->bodyText(organisationName: $organisationName, deepLink: $deepLink));
            $message->setTo([$email]);

            $failedRecipients = $this->mailer->send($message);
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
     * The bilingual (NL first, EN second) B1-level subject line. Privacy-minimal
     * by construction: the ONLY variable is the organisation display name.
     *
     * @param string $organisationName The tenant's display name.
     *
     * @return string
     */
    private function subjectLine(string $organisationName): string
    {
        $nlText = $this->l10nFactory->get('portaliq', 'nl')->t(self::SUBJECT_KEY, [$organisationName]);
        $enText = $this->l10nFactory->get('portaliq', 'en')->t(self::SUBJECT_KEY, [$organisationName]);

        return $nlText.' / '.$enText;
    }//end subjectLine()

    /**
     * The bilingual (NL first, EN second) B1-level body text. Privacy-minimal
     * by construction: the ONLY variables are the organisation display name and
     * the deep link — never the message subject, body, case identifiers, or any
     * data beyond the recipient address (design.md).
     *
     * @param string $organisationName The tenant's display name.
     * @param string $deepLink         The deep link into the authenticated portal.
     *
     * @return string
     */
    private function bodyText(string $organisationName, string $deepLink): string
    {
        $nlText = $this->l10nFactory->get('portaliq', 'nl')->t(self::BODY_KEY, [$organisationName, $deepLink]);
        $enText = $this->l10nFactory->get('portaliq', 'en')->t(self::BODY_KEY, [$organisationName, $deepLink]);

        return $nlText."\n\n".$enText;
    }//end bodyText()

    /**
     * Build the deep link into the portal (`/portal?org=<slug>`, landing at the
     * authenticated inbox after login) — content is only ever shown behind the
     * portal auth edge (design.md).
     *
     * @param string $organisation The tenant slug.
     *
     * @return string
     */
    private function deepLink(string $organisation): string
    {
        $base = $this->urlGenerator->getAbsoluteURL('/portal');
        if ($organisation === '') {
            return $base;
        }

        return $base.'?org='.rawurlencode($organisation);
    }//end deepLink()

    /**
     * Resolve the subject's OWN portalAccount, scoped exactly like every other
     * portal read (subjectRef + tenant), or null. The job payload also carries
     * `audience` (for parity with the trigger's subject shape), but this
     * lookup scopes by subjectRef alone, which is already globally unique.
     *
     * @param string $subjectRef   The subject's own reference.
     * @param string $organisation The subject's tenant (may be empty).
     *
     * @return array<string, mixed>|null
     */
    private function findAccount(string $subjectRef, string $organisation): ?array
    {
        $rows = $this->reader->readCollection(
            register: self::REGISTER,
            schema: self::ACCOUNT_SCHEMA,
            scopeField: 'subjectRef',
            subjectRef: $subjectRef,
            organisation: $organisation,
            limit: 2
        );

        return ($rows[0] ?? null);
    }//end findAccount()

    /**
     * The consecutive-failure streak going into THIS attempt: the `attempts`
     * value of the most recent `portalNotification` row for this
     * (accountId, ruleKey), or 0 when there is no prior history (or the most
     * recent row was itself a success). Read-only — this row is never mutated,
     * a new one is always appended.
     *
     * @param string $accountId    The account's id/uuid.
     * @param string $organisation The tenant (may be empty).
     * @param string $ruleKey      The rule key to match.
     *
     * @return int
     */
    private function previousFailureStreak(string $accountId, string $organisation, string $ruleKey): int
    {
        $rows = $this->reader->readCollection(
            register: self::REGISTER,
            schema: self::NOTIFICATION_SCHEMA,
            scopeField: 'accountRef',
            subjectRef: $accountId,
            organisation: $organisation,
            limit: 50
        );

        $matching = [];
        foreach ($rows as $row) {
            if ((string) ($row['ruleKey'] ?? '') === $ruleKey) {
                $matching[] = $row;
            }
        }

        if (count($matching) === 0) {
            return 0;
        }

        usort(
            $matching,
            static fn (array $a, array $b): int => strcmp((string) ($b['lastAttemptAt'] ?? ''), (string) ($a['lastAttemptAt'] ?? ''))
        );

        $latest = $matching[0];
        if ((string) ($latest['status'] ?? '') === 'sent') {
            return 0;
        }

        return (int) ($latest['attempts'] ?? 0);
    }//end previousFailureStreak()

    /**
     * Append a new `portalNotification` row for this attempt (never updated in
     * place — an honest per-attempt log, mirroring the WMEBV burden-of-proof
     * append-only convention `portalSubmission` already uses).
     *
     * @param string $accountId    The account's id/uuid (stamped as `accountRef`).
     * @param string $organisation The tenant (may be empty).
     * @param string $ruleKey      The matched rule key.
     * @param string $appId        The contributing app.
     * @param string $subjectRef   The subject (audit-trail only; not the scope field).
     * @param string $status       `sent` or `failed`.
     * @param int    $attempts     The consecutive-failure counter as of this attempt.
     *
     * @return void
     */
    private function recordAttempt(
        string $accountId,
        string $organisation,
        string $ruleKey,
        string $appId,
        string $subjectRef,
        string $status,
        int $attempts
    ): void {
        $created = $this->writer->createObject(
            register: self::REGISTER,
            schema: self::NOTIFICATION_SCHEMA,
            scopeField: 'accountRef',
            subjectRef: $accountId,
            organisation: $organisation,
            data: [
                'ruleKey'       => $ruleKey,
                'appId'         => $appId,
                'channel'       => self::CHANNEL_EMAIL,
                'status'        => $status,
                'attempts'      => $attempts,
                'lastAttemptAt' => gmdate('c'),
            ]
        );

        if ($created === null) {
            $this->logger->warning(
                'Portaliq: portalNotification log write failed — the dispatch attempt itself already ran',
                ['subjectRef' => $subjectRef, 'ruleKey' => $ruleKey, 'status' => $status]
            );
        }
    }//end recordAttempt()

    /**
     * Read the configurable consecutive-failure threshold, or the small
     * default when unset/invalid (fail-safe — never zero, which would flag
     * every single failure as an immediate fallback).
     *
     * @return int
     */
    private function failureThreshold(): int
    {
        $raw = (int) $this->config->getAppValue(Application::APP_ID, self::THRESHOLD_CONFIG_KEY, (string) self::DEFAULT_THRESHOLD);
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
     * @param string $subjectRef   The account's own subject reference.
     * @param string $organisation The tenant (may be empty).
     * @param string $accountId    The account's id/uuid.
     * @param bool   $flag         True to set, false to clear.
     *
     * @return void
     */
    private function flagNeedsAlternativeContact(string $subjectRef, string $organisation, string $accountId, bool $flag): void
    {
        $updated = $this->writer->updateObject(
            register: self::REGISTER,
            schema: self::ACCOUNT_SCHEMA,
            scopeField: 'subjectRef',
            subjectRef: $subjectRef,
            organisation: $organisation,
            id: $accountId,
            data: ['needsAlternativeContact' => $flag]
        );

        if ($updated === null) {
            $this->logger->warning(
                'Portaliq: failed to update portalAccount.needsAlternativeContact',
                ['subjectRef' => $subjectRef, 'flag' => $flag]
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
    private function rowId(array $row): ?string
    {
        $self     = ($row['@self'] ?? null);
        $selfUuid = null;
        $selfId   = null;
        if (is_array($self) === true) {
            $selfUuid = ($self['uuid'] ?? null);
            $selfId   = ($self['id'] ?? null);
        }

        $candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), $selfUuid, $selfId];
        foreach ($candidates as $candidate) {
            if ((is_string($candidate) === true || is_int($candidate) === true) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return null;
    }//end rowId()
}//end class
