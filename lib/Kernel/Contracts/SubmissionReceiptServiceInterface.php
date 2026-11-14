<?php

/**
 * Contract of SubmissionReceiptService
 *
 * The public surface of SubmissionReceiptService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of SubmissionReceiptService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubmissionReceiptServiceInterface {

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
	): void;

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
	): array;
}//end interface
