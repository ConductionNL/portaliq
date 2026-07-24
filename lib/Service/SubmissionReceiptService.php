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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fail-safe generator of the WMEBV ontvangstbevestiging + proof-of-receipt log.
 *
 * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
 */
class SubmissionReceiptService
{
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
     * The receipt subject-line translation key. English key text per
     * convention — `l10n/nl.json` supplies the Dutch translation for this
     * EXACT string; `l10n/en.json` maps it to itself.
     */
    private const SUBJECT_KEY = 'Confirmation of receipt — reference %1$s';

    /**
     * The receipt body-text translation key (B1 language level). English key
     * text per convention — translated in `l10n/nl.json`.
     */
    private const BODY_KEY = 'We received your submission on %2$s (reference %1$s). '
        .'This message confirms receipt; we will contact you if further action is needed.';

    /**
     * Constructor.
     *
     * @param PortalObjectWriter          $writer               Subject-scoped OR writer (same
     *                                                          one the create used).
     * @param IFactory                    $l10nFactory          Resolves NL/EN translators
     *                                                          independent of any session locale
     *                                                          (portal subjects are not NC users).
     * @param ITimeFactory                $timeFactory          Testable clock for the ISO-8601
     *                                                          timestamps.
     * @param LoggerInterface             $logger               The logger.
     * @param NotificationDispatchService $notificationDispatch Fires the `message.created` trigger
     *                                                          (portal-notifications-dispatch)
     *                                                          on a successful receipt write; itself
     *                                                          fail-safe, never affects this call.
     */
    public function __construct(
        private readonly PortalObjectWriter $writer,
        private readonly IFactory $l10nFactory,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
        private readonly NotificationDispatchService $notificationDispatch,
    ) {
    }//end __construct()

    /**
     * Record the WMEBV receipt + proof log for one successful create-action.
     *
     * Never throws and never returns a value the caller could branch on — by
     * design, a WMEBV side-effect can only ever be a follow-on to an ALREADY
     * successful domain write, never a gate on it.
     *
     * @param string               $subjectRef      The submitting subject (server-derived).
     * @param string               $organisation    The subject's tenant (may be empty).
     * @param string               $appId           The contributing app the action belongs to.
     * @param string               $actionId        The declared action id that was submitted.
     * @param array<string, mixed> $whitelistedData The ALREADY-whitelisted field map the
     *                                              domain create persisted (never the raw
     *                                              client body) — the WMEBV "copy of
     *                                              submitted data".
     * @param string               $audience        The subject's audience (portal-notifications-
     *                                              dispatch — resolves the app manifest via the
     *                                              registry; empty is safe, it simply yields no
     *                                              notification match).
     *
     * @return void
     *
     * @spec openspec/specs/supplier-portal/spec.md#automatic-ontvangstbevestiging-on-a-successful-create-action
     * @spec openspec/specs/supplier-portal/spec.md#proof-of-receipt-log-satisfying-the-wmebv-burden-of-proof
     * @spec openspec/specs/supplier-portal/spec.md#a-receipt-or-log-failure-never-loses-the-submission
     * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
     */
    public function record(
        string $subjectRef,
        string $organisation,
        string $appId,
        string $actionId,
        array $whitelistedData,
        string $audience=''
    ): void {
        try {
            $this->doRecord(
                subjectRef: $subjectRef,
                organisation: $organisation,
                appId: $appId,
                actionId: $actionId,
                whitelistedData: $whitelistedData,
                audience: $audience
            );
        } catch (Throwable $e) {
            // Belt-and-braces: doRecord()'s own writes already degrade to null
            // rather than throw, but nothing here may ever reach the caller —
            // a submission must never be lost because a compliance side-effect
            // misbehaved.
            $this->logger->error(
                'Portaliq: WMEBV receipt recording failed entirely',
                ['appId' => $appId, 'actionId' => $actionId, 'reason' => $e->getMessage()]
            );
            $this->writeFallbackSubmission(subjectRef: $subjectRef, organisation: $organisation, appId: $appId, actionId: $actionId);
        }
    }//end record()

    /**
     * Write the receipt message, then the linked proof-log record.
     *
     * @param string               $subjectRef      The submitting subject.
     * @param string               $organisation    The subject's tenant.
     * @param string               $appId           The contributing app.
     * @param string               $actionId        The declared action id.
     * @param array<string, mixed> $whitelistedData The whitelisted submitted data.
     * @param string               $audience        The subject's audience (notification dispatch).
     *
     * @return void
     */
    private function doRecord(
        string $subjectRef,
        string $organisation,
        string $appId,
        string $actionId,
        array $whitelistedData,
        string $audience=''
    ): void {
        $referenceId = $this->generateReferenceId();
        $submittedAt = $this->now();

        $message = $this->writer->createObject(
            register: self::REGISTER,
            schema: self::MESSAGE_SCHEMA,
            scopeField: 'subjectRef',
            subjectRef: $subjectRef,
            organisation: $organisation,
            data: [
                'subject'     => $this->subjectLine(referenceId: $referenceId),
                'body'        => $this->bodyText(referenceId: $referenceId, submittedAt: $submittedAt),
                'referenceId' => $referenceId,
                'dataCopy'    => $whitelistedData,
                'read'        => false,
                'receivedAt'  => $submittedAt,
            ]
        );

        $deliveryStatus = 'failed';
        if ($message !== null) {
            $deliveryStatus = 'delivered';

            // Portal-notifications-dispatch: the receipt message was written —
            // fire the `message.created` trigger. Fail-safe by construction
            // (NotificationDispatchService::dispatch never throws); this can
            // never affect the WMEBV receipt/proof-log outcome above.
            $this->notificationDispatch->dispatch(
                ruleKey: NotificationDispatchService::RULE_MESSAGE_CREATED,
                appId: $appId,
                subject: [
                    'subjectRef'   => $subjectRef,
                    'organisation' => $organisation,
                    'audience'     => $audience,
                ]
            );
        } else {
            $this->logger->warning(
                'Portaliq: WMEBV receipt message write failed — submission remains authoritative',
                ['appId' => $appId, 'actionId' => $actionId, 'subjectRef' => $subjectRef]
            );
        }//end if

        $submission = $this->writer->createObject(
            register: self::REGISTER,
            schema: self::SUBMISSION_SCHEMA,
            scopeField: 'subjectRef',
            subjectRef: $subjectRef,
            organisation: $organisation,
            data: [
                'appId'             => $appId,
                'actionId'          => $actionId,
                'payloadCopy'       => $whitelistedData,
                'receiptMessageRef' => $referenceId,
                'submittedAt'       => $submittedAt,
                'deliveryStatus'    => $deliveryStatus,
            ]
        );

        if ($submission === null) {
            $this->logger->warning(
                'Portaliq: WMEBV proof-log write failed — retrying with a minimal fallback record',
                ['appId' => $appId, 'actionId' => $actionId, 'subjectRef' => $subjectRef]
            );
            $this->writeFallbackSubmission(subjectRef: $subjectRef, organisation: $organisation, appId: $appId, actionId: $actionId);
        }
    }//end doRecord()

    /**
     * Best-effort minimal `portalSubmission` row when the full write failed —
     * so the burden-of-proof log carries SOMETHING retriable rather than a
     * silent gap. Itself guarded: a failure here is logged and swallowed, it
     * can never propagate (the domain create already succeeded long ago).
     *
     * @param string $subjectRef   The submitting subject.
     * @param string $organisation The subject's tenant.
     * @param string $appId        The contributing app.
     * @param string $actionId     The declared action id.
     *
     * @return void
     *
     * @spec openspec/specs/supplier-portal/spec.md#a-receipt-or-log-failure-never-loses-the-submission
     */
    private function writeFallbackSubmission(string $subjectRef, string $organisation, string $appId, string $actionId): void
    {
        try {
            $this->writer->createObject(
                register: self::REGISTER,
                schema: self::SUBMISSION_SCHEMA,
                scopeField: 'subjectRef',
                subjectRef: $subjectRef,
                organisation: $organisation,
                data: [
                    'appId'          => $appId,
                    'actionId'       => $actionId,
                    'submittedAt'    => $this->now(),
                    'deliveryStatus' => 'failed',
                ]
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'Portaliq: WMEBV fallback proof-log write also failed',
                ['appId' => $appId, 'actionId' => $actionId, 'reason' => $e->getMessage()]
            );
        }
    }//end writeFallbackSubmission()

    /**
     * A short, unique receipt reference id (not a secret — merely an
     * evidentiary correlation key shown to the subject and linked from the
     * proof log). Generated up front so it can be embedded in the receipt's
     * own body text at write time.
     *
     * @return string
     */
    private function generateReferenceId(): string
    {
        try {
            $random = bin2hex(random_bytes(8));
        } catch (Throwable) {
            // Randomness generation failing is exceptionally rare; degrade to a
            // still-unique-enough fallback rather than block the receipt.
            $random = str_pad((string) mt_rand(0, PHP_INT_MAX), 16, '0', STR_PAD_LEFT);
        }

        return 'WMEBV-'.strtoupper($random);
    }//end generateReferenceId()

    /**
     * The current time as an ISO-8601 string (testable via ITimeFactory).
     *
     * @return string
     */
    private function now(): string
    {
        return gmdate('c', $this->timeFactory->getTime());
    }//end now()

    /**
     * The bilingual (NL first, EN second) B1-level receipt subject line.
     *
     * @param string $referenceId The receipt's reference id.
     *
     * @return string
     */
    private function subjectLine(string $referenceId): string
    {
        $nlText = $this->l10nFactory->get('portaliq', 'nl')->t(self::SUBJECT_KEY, [$referenceId]);
        $enText = $this->l10nFactory->get('portaliq', 'en')->t(self::SUBJECT_KEY, [$referenceId]);

        return $nlText.' / '.$enText;
    }//end subjectLine()

    /**
     * The bilingual (NL first, EN second) B1-level receipt body text — plain,
     * short sentences, no jargon, satisfying the WMEBV ontvangstbevestiging
     * duty regardless of which language the subject reads first.
     *
     * @param string $referenceId The receipt's reference id.
     * @param string $submittedAt The ISO-8601 submission timestamp.
     *
     * @return string
     */
    private function bodyText(string $referenceId, string $submittedAt): string
    {
        $nlText = $this->l10nFactory->get('portaliq', 'nl')->t(self::BODY_KEY, [$referenceId, $submittedAt]);
        $enText = $this->l10nFactory->get('portaliq', 'en')->t(self::BODY_KEY, [$referenceId, $submittedAt]);

        return $nlText."\n\n".$enText;
    }//end bodyText()
}//end class
