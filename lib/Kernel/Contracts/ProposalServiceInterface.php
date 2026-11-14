<?php

/**
 * Contract of ProposalService
 *
 * The public surface of ProposalService that other components call, so they depend
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
 * Contract of ProposalService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProposalServiceInterface {

	/**
	 * A proposal waiting for a reviewer.
	 */
	public const STATE_QUEUED = 'queued';

	/**
	 * Accept a proposal: write the record as the reviewer, then close the
	 * proposal.
	 *
	 * The order is deliberate. The record is written first, with the
	 * reviewer's own rights, and the proposal is only marked accepted once
	 * that write landed: a proposal reading `accepted` beside a record that
	 * never changed is the one outcome worth preventing.
	 *
	 * @param array<string, mixed> $proposal The queued proposal.
	 * @param array<string, mixed> $subjectRow The record as it stands now.
	 * @param string $reviewer The reviewer's user id.
	 *
	 * @return array{accepted: true}|array{error: string, drift?: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function accept(array $proposal, array $subjectRow, string $reviewer): array;

	/**
	 * Accept a proposal the reviewer has been shown drift on and confirmed.
	 *
	 * The separate method is the point. Accepting a proposal and accepting one
	 * over a record that moved underneath it are two decisions, and the second
	 * is the one somebody has to take deliberately. A flag on the first would
	 * let it be taken by leaving a checkbox ticked.
	 *
	 * @param array<string, mixed> $proposal The queued proposal.
	 * @param string $reviewer The reviewer's user id.
	 *
	 * @return array{accepted: true}|array{error: string}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function acceptConfirmingDrift(array $proposal, string $reviewer): array;

	/**
	 * The queued proposals on one record.
	 *
	 * @param array<string, string> $subject `register`, `schema` and `id`.
	 * @param string $state The state to list, or '' for every state.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function forSubject(array $subject, string $state = self::STATE_QUEUED): array;

	/**
	 * Every proposal a proposer made, any state.
	 *
	 * Unlike `forSubject()`, no reviewer permission gates this: the filter
	 * IS the authorization. The caller MUST derive `$proposedBy` from the
	 * subject's own session, never from client input — passing an
	 * arbitrary reference here would turn this into an unscoped read.
	 *
	 * @param string $proposedBy Who proposed it, from the session.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md#requirement-a-proposer-can-list-their-own-proposals-req-cpq-005
	 */
	public function mine(string $proposedBy): array;

	/**
	 * Queue a proposal against a record.
	 *
	 * @param array<string, string> $subject `register`, `schema` and `id`.
	 * @param array<int, array<string, mixed>> $changes Each `property` and `proposedValue`.
	 * @param array<int, string> $proposable The properties the contribution allows.
	 * @param array<string, mixed> $subjectRow The record as it stands, for the snapshot.
	 * @param string $proposedBy Who proposed it, from the session.
	 * @param string $channel `portal` or `staff`.
	 * @param string $note What the proposer said.
	 *
	 * @return array{proposal: array<string, mixed>}|array{error: string, property?: string}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- the parameters are the
	 * proposal's declared fields plus the allow-list it is judged against.
	 */
	public function propose(
		array $subject,
		array $changes,
		array $proposable,
		array $subjectRow,
		string $proposedBy,
		string $channel = 'portal',
		string $note = '',
	): array;

	/**
	 * Reject a proposal, with a reason, touching only the proposal.
	 *
	 * @param array<string, mixed> $proposal The queued proposal.
	 * @param string $reviewer The reviewer's user id.
	 * @param string $reason Why it is rejected.
	 *
	 * @return array{rejected: true}|array{error: string}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function reject(array $proposal, string $reviewer, string $reason): array;

	/**
	 * Withdraw a proposal. Only its own proposer may, and only while queued.
	 *
	 * @param array<string, mixed> $proposal The queued proposal.
	 * @param string $proposedBy Who is asking.
	 *
	 * @return array{withdrawn: true}|array{error: string}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function withdraw(array $proposal, string $proposedBy): array;
}//end interface
