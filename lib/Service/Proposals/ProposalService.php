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
 * @spec openspec/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Proposals;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Queues, reviews and applies change proposals.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
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
	 * @param PortalObjectWriter $writer Records the proposal itself.
	 * @param ReviewerObjectWriter $reviewerWriter Writes the subject as the
	 *                                             reviewer, with their rights.
	 * @param ProposalQueueReader $queueReader The read half — which
	 *                                         proposals, scoped one field at
	 *                                         a time (kept a separate class
	 *                                         so this one stays "what may
	 *                                         happen to one").
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly ReviewerObjectWriter $reviewerWriter,
		private readonly ProposalQueueReader $queueReader,
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
	): array {
		$request = new ChangeProposalRequest(
			subject: $subject,
			changes: $changes,
			proposable: $proposable,
			subjectRow: $subjectRow,
			proposedBy: $proposedBy,
			channel: $channel,
			note: $note
		);
		return $this->proposeChange(request: $request);
	}//end propose()

	/**
	 * Queue a proposal against a record.
	 *
	 * @param ChangeProposalRequest $request The record, the changes and who asks.
	 *
	 * @return array{proposal: array<string, mixed>}|array{error: string, property?: string}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	public function proposeChange(ChangeProposalRequest $request): array {
		$checked = $this->checkedChanges(request: $request);
		if (isset($checked['error']) === true) {
			return $checked;
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			data: $this->proposalRow(request: $request, recorded: $checked['changes'])
		);
		if ($created === null) {
			return ['error' => 'not_recorded'];
		}

		return ['proposal' => $created];
	}//end proposeChange()

	/**
	 * The changes to record with their snapshot, or why the proposal is refused.
	 *
	 * @param ChangeProposalRequest $request The record, the changes and who asks.
	 *
	 * @return array{changes: array<int, array<string, mixed>>}|array{error: string, property?: string}
	 */
	private function checkedChanges(ChangeProposalRequest $request): array {
		if ($request->proposedBy === '' || ($request->subject['id'] ?? '') === '') {
			return ['error' => 'refused'];
		}

		$named        = $this->namedChanges(changes: $request->changes);
		$unproposable = $this->firstUnproposable(named: $named, proposable: $request->proposable);
		if ($unproposable !== '') {
			// The allow-list is the contribution's, and it is checked here
			// rather than in the UI: a property nobody listed is not
			// proposable however the request was made.
			return ['error' => 'property_not_proposable', 'property' => $unproposable];
		}

		$recorded = $this->snapshot(named: $named, subjectRow: $request->subjectRow);
		if ($recorded === []) {
			return ['error' => 'nothing_proposed'];
		}

		return ['changes' => $recorded];
	}//end checkedChanges()

	/**
	 * The changes that name a property, each with that property's name;
	 * anything else in the list is skipped.
	 *
	 * @param array<int, mixed> $changes Each `property` and `proposedValue`.
	 *
	 * @return array<int, array{0: string, 1: array<string, mixed>}> Property name and change.
	 */
	private function namedChanges(array $changes): array {
		$named = [];
		foreach ($changes as $change) {
			if (is_array($change) === false) {
				continue;
			}

			$property = (string)($change['property'] ?? '');
			if ($property !== '') {
				$named[] = [$property, $change];
			}
		}

		return $named;
	}//end namedChanges()

	/**
	 * The first named property the contribution does not allow, '' when all are allowed.
	 *
	 * @param array<int, array{0: string, 1: array<string, mixed>}> $named      Property name and change.
	 * @param array<int, string>                                    $proposable The properties the contribution allows.
	 *
	 * @return string
	 */
	private function firstUnproposable(array $named, array $proposable): string {
		foreach ($named as $entry) {
			if (in_array($entry[0], $proposable, true) === false) {
				return $entry[0];
			}
		}

		return '';
	}//end firstUnproposable()

	/**
	 * Each change with the snapshot: what the record said at this moment.
	 *
	 * @param array<int, array{0: string, 1: array<string, mixed>}> $named      Property name and change.
	 * @param array<string, mixed>                                  $subjectRow The record as it stands.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function snapshot(array $named, array $subjectRow): array {
		$recorded = [];
		foreach ($named as $entry) {
			$recorded[] = [
				'property' => $entry[0],
				'currentValue' => ($subjectRow[$entry[0]] ?? null),
				'proposedValue' => ($entry[1]['proposedValue'] ?? null),
			];
		}

		return $recorded;
	}//end snapshot()

	/**
	 * The fields of a newly queued proposal.
	 *
	 * @param ChangeProposalRequest            $request  The record, the changes and who asks.
	 * @param array<int, array<string, mixed>> $recorded The changes with their snapshot.
	 *
	 * @return array<string, mixed>
	 */
	private function proposalRow(ChangeProposalRequest $request, array $recorded): array {
		return [
			'subjectRegister' => (string)($request->subject['register'] ?? ''),
			'subjectSchema' => (string)($request->subject['schema'] ?? ''),
			'subjectId' => (string)($request->subject['id'] ?? ''),
			'proposedBy' => $request->proposedBy,
			'channel' => $request->channel,
			'changes' => $recorded,
			'note' => $request->note,
			'state' => self::STATE_QUEUED,
			'proposedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];
	}//end proposalRow()

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
	public function forSubject(array $subject, string $state = self::STATE_QUEUED): array {
		return $this->queueReader->forSubject(subject: $subject, state: $state);
	}//end forSubject()

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
	public function mine(string $proposedBy): array {
		return $this->queueReader->mine(proposedBy: $proposedBy);
	}//end mine()

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
	public function accept(array $proposal, array $subjectRow, string $reviewer): array {
		$refusal = $this->refusalBeforeApplying(proposal: $proposal, reviewer: $reviewer);
		if ($refusal !== null) {
			return $refusal;
		}

		$drift = $this->drift(proposal: $proposal, subjectRow: $subjectRow);
		if ($drift !== []) {
			// The record moved since the proposal was made. The reviewer is
			// shown both values and asked again, rather than silently
			// overwriting a change they never saw.
			return ['error' => 'drifted', 'drift' => $drift];
		}

		return $this->applyProposal(proposal: $proposal, reviewer: $reviewer);
	}//end accept()

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
	public function acceptConfirmingDrift(array $proposal, string $reviewer): array {
		$refusal = $this->refusalBeforeApplying(proposal: $proposal, reviewer: $reviewer);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->applyProposal(proposal: $proposal, reviewer: $reviewer);
	}//end acceptConfirmingDrift()

	/**
	 * Why this proposal cannot be applied at all, or null when it can.
	 *
	 * Asked by both accept paths, so neither can forget it.
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @param string $reviewer The reviewer's user id.
	 *
	 * @return array{error: string}|null
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	private function refusalBeforeApplying(array $proposal, string $reviewer): ?array {
		if ((string)($proposal['state'] ?? '') !== self::STATE_QUEUED) {
			return ['error' => 'not_queued'];
		}

		if ($reviewer === '') {
			return ['error' => 'forbidden'];
		}

		return null;
	}//end refusalBeforeApplying()

	/**
	 * Write the proposal's values onto the record and close the proposal.
	 *
	 * @param array<string, mixed> $proposal The proposal.
	 * @param string $reviewer The reviewer's user id.
	 *
	 * @return array{accepted: true}|array{error: string}
	 *
	 * @spec openspec/specs/change-proposal-queue/spec.md
	 */
	private function applyProposal(array $proposal, string $reviewer): array {
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
	}//end applyProposal()

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
	 * @spec openspec/specs/change-proposal-queue/spec.md
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
	 * @spec openspec/specs/change-proposal-queue/spec.md
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
