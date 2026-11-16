<?php

/**
 * Contract of DestinationDraft
 *
 * The public surface of DestinationDraft that other components call, so they depend
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
 * Contract of DestinationDraft.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DestinationDraftInterface {

	/**
	 * Discard the subject's draft. Someone else's draft, or a sent object, is left alone.
	 *
	 * @param array{register: string, schema: string} $destination The destination pair.
	 * @param string                                  $subjectRef  The signed-in subject.
	 * @param string                                  $draftId     The draft.
	 *
	 * @return bool True when it was removed.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function discardDraft(array $destination, string $subjectRef, string $draftId): bool;

	/**
	 * A refusal's findings per field, for the form: a create action's field is its property.
	 *
	 * @param array<int, array<string, mixed>> $findings The destination's findings.
	 *
	 * @return array<string, string> The first message per field; '_form' for a finding without a property.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function errorsOf(array $findings): array;

	/**
	 * Save the subject's draft of a destination: the destination object itself, in status `draft`.
	 *
	 * Decision 180: the draft may miss required answers, never hold a
	 * type-invalid one (422 with findings). Decision 195: its owner is
	 * `portal:<subjectRef>`, so only this subject reads, saves or sends it.
	 *
	 * @param array{register: string, schema: string} $destination The destination pair.
	 * @param array<string, mixed>                    $payload     The answers so far, by property.
	 * @param string                                  $subjectRef  The signed-in subject.
	 * @param string                                  $draftId     The draft to update, or '' to start one.
	 * @param string                                  $scope       What upload tokens belong to.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 *         On success `answer` holds the draft's `id`.
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function saveDraft(array $destination, array $payload, string $subjectRef, string $draftId = '', string $scope = ''): array;

	/**
	 * Send the subject's draft: it leaves `draft` with full validation and gets its reference.
	 *
	 * @param array{register: string, schema: string} $destination    The destination pair.
	 * @param array<string, mixed>                    $payload        The final answers, by property.
	 * @param string                                  $subjectRef     The signed-in subject.
	 * @param string                                  $draftId        The draft.
	 * @param string                                  $idempotencyKey The client's Idempotency-Key, or ''.
	 *
	 * @return array{ok: bool, status: int, answer: array<string, mixed>, message: string, findings: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-a-saved-form-is-a-draft-of-its-destination-object-req-pifo-008
	 */
	public function sendDraft(array $destination, array $payload, string $subjectRef, string $draftId, string $idempotencyKey = ''): array;
}//end interface
