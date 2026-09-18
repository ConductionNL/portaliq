<?php

/**
 * Portaliq Proposal Service
 *
 * A citizen or a colleague proposes a field change on a record they may read
 * but not write. The proposal queues on the record until somebody with write
 * rights accepts or rejects it.
 *
 * Two properties of this are what make it safe. A proposal never writes
 * anything: it is an object of its own, and only `accept()` touches the
 * record, as the reviewer, with their rights. And each change carries the
 * value the property had when the proposal was made, so a reviewer accepting
 * a week-old proposal can be shown that the record moved underneath it and
 * asked whether they still mean it.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Proposals
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
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Proposals;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Queues, reviews and applies change proposals.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ProposalService {
	/**
	 * A proposal waiting for a reviewer.
	 */
	public const STATE_QUEUED = 'queued';

	/**
	 * A proposal whose values are on the record.
	 */
	public const STATE_ACCEPTED = 'accepted';

	/**
	 * A proposal a reviewer refused, with a reason.
	 */
	public const STATE_REJECTED = 'rejected';

	/**
	 * A proposal its own proposer took back.
	 */
	public const STATE_WITHDRAWN = 'withdrawn';

	/**
	 * The register the proposal lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a proposal.
	 */
	private const SCHEMA = 'changeProposal';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads proposals and the subject.
	 * @param PortalObjectWriter $writer Records the proposal itself.
	 * @param ReviewerObjectWriter $reviewerWriter Writes the subject as the
	 *                                             reviewer, with their rights.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ReviewerObjectWriter $reviewerWriter,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
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
	): array {
		if ($proposedBy === '' || ($subject['id'] ?? '') === '') {
			return ['error' => 'refused'];
		}

		$recorded = [];
		foreach ($changes as $change) {
			if (is_array($change) === false) {
				continue;
			}

			$property = (string)($change['property'] ?? '');
			if ($property === '') {
				continue;
			}

			if (in_array($property, $proposable, true) === false) {
				// The allow-list is the contribution's, and it is checked here
				// rather than in the UI: a property nobody listed is not
				// proposable however the request was made.
				return ['error' => 'property_not_proposable', 'property' => $property];
			}

			$recorded[] = [
				'property' => $property,
				// The snapshot: what the record said at this moment.
				'currentValue' => ($subjectRow[$property] ?? null),
				'proposedValue' => ($change['proposedValue'] ?? null),
			];
		}//end foreach

		if ($recorded === []) {
			return ['error' => 'nothing_proposed'];
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: [
				'subjectRegister' => (string)($subject['register'] ?? ''),
				'subjectSchema' => (string)($subject['schema'] ?? ''),
				'subjectId' => (string)($subject['id'] ?? ''),
				'proposedBy' => $proposedBy,
				'channel' => $channel,
				'changes' => $recorded,
				'note' => $note,
				'state' => self::STATE_QUEUED,
				'proposedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return ['error' => 'not_recorded'];
		}

		return ['proposal' => $created];
	}//end propose()

	/**
	 * The queued proposals on one record.
	 *
	 * @param array<string, string> $subject `register`, `schema` and `id`.
	 * @param string $state The state to list, or '' for every state.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function forSubject(array $subject, string $state = self::STATE_QUEUED): array {
		$id = (string)($subject['id'] ?? '');
		if ($id === '') {
			return [];
		}

		$filter = ['subjectSchema' => (string)($subject['schema'] ?? '')];
		if ($state !== '') {
			$filter['state'] = $state;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectId',
			subjectRef: $id,
			organisation: '',
			limit: 200,
			filter: $filter
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['subjectId'] ?? '') === $id) {
				$out[] = $row;
			}
		}

		return $out;
	}//end forSubject()

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
	 * @param bool $confirmDrift Whether the reviewer confirmed a drifted snapshot.
	 *
	 * @return array{accepted: true}|array{error: string, drift?: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function accept(array $proposal, array $subjectRow, string $reviewer, bool $confirmDrift = false): array {
		if ((string)($proposal['state'] ?? '') !== self::STATE_QUEUED) {
			return ['error' => 'not_queued'];
		}

		if ($reviewer === '') {
			return ['error' => 'forbidden'];
		}

		$drift = $this->drift(proposal: $proposal, subjectRow: $subjectRow);
		if ($drift !== [] && $confirmDrift === false) {
			// The record moved since the proposal was made. The reviewer is
			// shown both values and asked again, rather than silently
			// overwriting a change they never saw.
			return ['error' => 'drifted', 'drift' => $drift];
		}

		$values = [];
		foreach ((array)($proposal['changes'] ?? []) as $change) {
			if (is_array($change) === false) {
				continue;
			}

			$property = (string)($change['property'] ?? '');
			if ($property !== '') {
				$values[$property] = ($change['proposedValue'] ?? null);
			}
		}

		if ($values === []) {
			return ['error' => 'nothing_to_apply'];
		}

		$written = $this->reviewerWriter->write(
			register: (string)($proposal['subjectRegister'] ?? ''),
			schema: (string)($proposal['subjectSchema'] ?? ''),
			id: (string)($proposal['subjectId'] ?? ''),
			values: $values
		);
		if ($written === false) {
			return ['error' => 'not_written'];
		}

		$closed = $this->close(proposal: $proposal, state: self::STATE_ACCEPTED, reviewer: $reviewer, reason: '');
		if ($closed === false) {
			return ['error' => 'not_closed'];
		}

		return ['accepted' => true];
	}//end accept()

	/**
	 * Reject a proposal, with a reason, touching only the proposal.
	 *
	 * @param array<string, mixed> $proposal The queued proposal.
	 * @param string $reviewer The reviewer's user id.
	 * @param string $reason Why it is rejected.
	 *
	 * @return array{rejected: true}|array{error: string}
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function reject(array $proposal, string $reviewer, string $reason): array {
		if ((string)($proposal['state'] ?? '') !== self::STATE_QUEUED) {
			return ['error' => 'not_queued'];
		}

		if (trim($reason) === '') {
			// A refusal nobody explained is one the proposer cannot answer.
			return ['error' => 'reason_required'];
		}

		if ($this->close(proposal: $proposal, state: self::STATE_REJECTED, reviewer: $reviewer, reason: trim($reason)) === false) {
			return ['error' => 'not_closed'];
		}

		return ['rejected' => true];
	}//end reject()

	/**
	 * Withdraw a proposal. Only its own proposer may, and only while queued.
	 *
	 * @param array<string, mixed> $proposal The queued proposal.
	 * @param string $proposedBy Who is asking.
	 *
	 * @return array{withdrawn: true}|array{error: string}
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function withdraw(array $proposal, string $proposedBy): array {
		if ((string)($proposal['state'] ?? '') !== self::STATE_QUEUED) {
			return ['error' => 'not_queued'];
		}

		if ($proposedBy === '' || (string)($proposal['proposedBy'] ?? '') !== $proposedBy) {
			return ['error' => 'forbidden'];
		}

		if ($this->close(proposal: $proposal, state: self::STATE_WITHDRAWN, reviewer: '', reason: '') === false) {
			return ['error' => 'not_closed'];
		}

		return ['withdrawn' => true];
	}//end withdraw()

	/**
	 * The properties whose current value no longer matches the snapshot.
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @param array<string, mixed> $subjectRow The record as it stands now.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function drift(array $proposal, array $subjectRow): array {
		$drift = [];
		foreach ((array)($proposal['changes'] ?? []) as $change) {
			if (is_array($change) === false) {
				continue;
			}

			$property = (string)($change['property'] ?? '');
			if ($property === '') {
				continue;
			}

			$now = ($subjectRow[$property] ?? null);
			if ($now !== ($change['currentValue'] ?? null)) {
				$drift[] = [
					'property' => $property,
					'snapshot' => ($change['currentValue'] ?? null),
					'current' => $now,
					'proposedValue' => ($change['proposedValue'] ?? null),
				];
			}
		}

		return $drift;
	}//end drift()

	/**
	 * Close a proposal in a terminal state.
	 *
	 * @param array<string, mixed> $proposal The proposal row.
	 * @param string $state The state to close it in.
	 * @param string $reviewer The reviewer, or '' on a withdrawal.
	 * @param string $reason The reason, or ''.
	 *
	 * @return bool
	 */
	private function close(array $proposal, string $state, string $reviewer, string $reason): bool {
		$id = (string)($proposal['uuid'] ?? $proposal['id'] ?? '');
		if ($id === '') {
			$self = (array)($proposal['@self'] ?? []);
			$id = (string)($self['uuid'] ?? $self['id'] ?? '');
		}

		if ($id === '') {
			return false;
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: [
				'state' => $state,
				'decidedBy' => $reviewer,
				'decidedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
				'decisionReason' => $reason,
			]
		) !== null;
	}//end close()
}//end class
