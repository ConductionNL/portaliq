<?php

/**
 * Contract of ActionDraft
 *
 * The public surface of ActionDraft that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of ActionDraft.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActionDraftInterface {

	/**
	 * The subject's create action with this id in this app, or null.
	 *
	 * @param array<string, mixed> $subject  The resolved subject.
	 * @param string               $appId    The contributing app.
	 * @param string               $actionId The action.
	 *
	 * @return array{action: array<string, mixed>, app: string}|null Null when the action is unknown,
	 *         is not a create with a register and schema, or asks more trust than the subject has.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function actionFor(array $subject, string $appId, string $actionId): ?array;

	/**
	 * The answers of a draft as the form shows them: only the action's own fields.
	 *
	 * @param array<string, mixed> $action The create action.
	 * @param array<string, mixed> $draft  The draft.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function answersOf(array $action, array $draft): array;

	/**
	 * What a draft of the action holds: the declared, non-file answers, the
	 * action's defaults, and the same ownership stamps a create writes.
	 *
	 * @param array{action: array<string, mixed>, app: string} $match   The action and its app.
	 * @param array<string, mixed>                             $subject The resolved subject.
	 * @param array<string, mixed>                             $answers The answers sent.
	 *
	 * @return array<string, mixed>|null Null when the action's scope claim does not resolve.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function bodyOf(array $match, array $subject, array $answers): ?array;

	/**
	 * Where the action's draft lives: the register and schema it creates in.
	 *
	 * @param array<string, mixed> $action The create action.
	 *
	 * @return array{register: string, schema: string}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function destinationOf(array $action): array;

	/**
	 * The subject's live draft of the action, or null. An expired one is discarded and reads as none.
	 *
	 * @param array<string, mixed>   $action     The create action.
	 * @param string                 $subjectRef The signed-in subject.
	 * @param DateTimeImmutable|null $now        The moment, for tests.
	 *
	 * @return array<string, mixed>|null `{id, status, data, updatedAt}`.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function find(array $action, string $subjectRef, ?DateTimeImmutable $now = null): ?array;

	/**
	 * The subject's live drafts, one per action that declares a draft and can
	 * keep one: its app, action, label, last save and expiry. A draft past its
	 * retention is discarded on the way, as find() does (REQ-FSR-003).
	 *
	 * @param array<string, mixed> $subject The resolved portal subject.
	 *
	 * @return array<int, array{app: string, action: string, label: string, updatedAt: string, expiresAt: string}>
	 *
	 * @spec openspec/changes/form-save-and-resume-dialogs/specs/site-forms/spec.md#requirement-the-own-area-lists-unfinished-forms-req-fsr-003
	 */
	public function liveDrafts(array $subject): array;

	/**
	 * The action's retention in days: its `draft.retentionDays` within 1 to 90, else 30.
	 *
	 * @param array<string, mixed> $action The create action.
	 *
	 * @return int Days.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function retentionDays(array $action): int;
}//end interface
