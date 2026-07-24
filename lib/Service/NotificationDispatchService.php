<?php

/**
 * Portaliq Notification Dispatch Service
 *
 * Consumes the contribution contract's previously-inert manifest
 * `notifications` rule keys (ADR-046): when a `portalMessage` is created for a
 * subject, or a status-transition update succeeds for a subject, AND the
 * contributing app's manifest declares a matching rule key, this service
 * enqueues a background job (`OCP\BackgroundJob\IJobList`) that dispatches a
 * privacy-minimal out-of-band email nudge — never inline, and never carrying
 * case content (design.md).
 *
 * Fail-safe by construction, mirroring SubmissionReceiptService's posture: the
 * triggering write (a message create, or a status transition) has ALREADY
 * succeeded by the time dispatch() runs — a notification side-effect must
 * never turn a successful domain action into a failed one, so every failure
 * here is caught, logged, and swallowed. Matching is fail-closed: an app that
 * declares no matching rule key, or a manifest whose `notifications` list is
 * missing/malformed, enqueues nothing.
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
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 * @spec openspec/specs/supplier-portal/spec.md#dispatch-is-decoupled-from-the-request-path
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\BackgroundJob\NotificationDispatchJob;
use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Matches a trigger against a contributing app's declared notification rule
 * keys, and enqueues the async dispatch job.
 *
 * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
 */
class NotificationDispatchService
{
    /**
     * Rule key for a `portalMessage` create (SubmissionReceiptService's WMEBV
     * receipt, or any other message-create path).
     */
    public const RULE_MESSAGE_CREATED = 'message.created';

    /**
     * Rule key for a successful status-transition update
     * (`ContributionController::update()`).
     */
    public const RULE_STATUS_CHANGED = 'status.changed';

    /**
     * Constructor.
     *
     * @param PortalContributionRegistry $registry Aggregates a subject's manifest,
     *                                             the SAME path every authorisation
     *                                             lookup flows through.
     * @param IJobList                   $jobList  Enqueues the async dispatch job —
     *                                             the send never runs inline.
     * @param LoggerInterface            $logger   The logger.
     */
    public function __construct(
        private readonly PortalContributionRegistry $registry,
        private readonly IJobList $jobList,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Match a trigger against the contributing app's manifest and enqueue the
     * dispatch job on a hit. Never throws — a notification side-effect can
     * never turn an already-successful domain action into a failed one.
     *
     * @param string               $ruleKey The trigger's rule key (RULE_MESSAGE_CREATED
     *                                      or RULE_STATUS_CHANGED).
     * @param string               $appId   The contributing app whose manifest is checked.
     * @param array<string, mixed> $subject The resolved subject (subjectRef, organisation,
     *                                      audience — audience is REQUIRED to resolve the
     *                                      app's manifest via the registry; trust is optional).
     *
     * @return void
     *
     * @spec openspec/specs/supplier-portal/spec.md#manifest-notification-rule-keys-drive-an-out-of-band-email
     * @spec openspec/specs/supplier-portal/spec.md#dispatch-is-decoupled-from-the-request-path
     */
    public function dispatch(string $ruleKey, string $appId, array $subject): void
    {
        try {
            $this->doDispatch(ruleKey: $ruleKey, appId: $appId, subject: $subject);
        } catch (Throwable $e) {
            $this->logger->error(
                'Portaliq: notification dispatch matching failed — no email will be sent for this trigger',
                ['appId' => $appId, 'ruleKey' => $ruleKey, 'reason' => $e->getMessage()]
            );
        }
    }//end dispatch()

    /**
     * The actual matching + enqueue logic, isolated so dispatch() can wrap it
     * in a single fail-safe try/catch.
     *
     * @param string               $ruleKey The trigger's rule key.
     * @param string               $appId   The contributing app.
     * @param array<string, mixed> $subject The resolved subject.
     *
     * @return void
     */
    private function doDispatch(string $ruleKey, string $appId, array $subject): void
    {
        if ($ruleKey === '' || $appId === '') {
            return;
        }

        $subjectRef = (string) ($subject['subjectRef'] ?? '');
        if ($subjectRef === '') {
            return;
        }

        // Aggregate through the ONE path every authorisation lookup flows
        // through — the same manifest the controller/registry already trust.
        $aggregate = $this->registry->aggregateFor(subject: $subject);

        foreach (($aggregate['contributions'] ?? []) as $contribution) {
            if (is_array($contribution) === false || (string) ($contribution['app'] ?? '') !== $appId) {
                continue;
            }

            if ($this->declaresRuleKey(contribution: $contribution, ruleKey: $ruleKey) === false) {
                // Fail-closed: no matching (or malformed/missing) declaration
                // means this contribution gets no email for this trigger.
                return;
            }

            $this->jobList->add(
                NotificationDispatchJob::class,
                [
                    'subjectRef'   => $subjectRef,
                    'organisation' => (string) ($subject['organisation'] ?? ''),
                    'audience'     => (string) ($subject['audience'] ?? ''),
                    'appId'        => $appId,
                    'ruleKey'      => $ruleKey,
                ]
            );

            return;
        }//end foreach
    }//end doDispatch()

    /**
     * Whether a contribution's `notifications` list declares the exact rule
     * key. A missing or non-array `notifications` — or a list containing only
     * non-string / non-matching entries — fails closed to false (ADR-005): an
     * unknown or malformed declaration must never widen into an email being
     * sent.
     *
     * @param array<string, mixed> $contribution One app's aggregated contribution manifest.
     * @param string               $ruleKey      The trigger's rule key.
     *
     * @return bool
     */
    private function declaresRuleKey(array $contribution, string $ruleKey): bool
    {
        $notifications = ($contribution['notifications'] ?? null);
        if (is_array($notifications) === false) {
            return false;
        }

        foreach ($notifications as $declared) {
            if (is_string($declared) === true && $declared === $ruleKey) {
                return true;
            }
        }

        return false;
    }//end declaresRuleKey()
}//end class
