<?php

/**
 * Portaliq Proposal Queue Reader
 *
 * The read half of the change-proposal queue, split out of ProposalService so
 * each class stays a single, small responsibility: this one answers "which
 * proposals", ProposalService decides what may happen to one. Every read here
 * is scoped by exactly one field (the record's own id for a reviewer's queue,
 * the proposer's own reference for a proposer's own list) and re-verified per
 * row after the query — the same belt-and-braces the reader's own scoped
 * query already wears elsewhere in this app.
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
 * @spec openspec/changes/guardian-self-service-profile/specs/change-proposal-queue/spec.md#requirement-a-proposer-can-list-their-own-proposals-req-cpq-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Proposals;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Scoped reads over the `changeProposal` collection.
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */
class ProposalQueueReader {
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
	 * @param PortalObjectReader $reader Reads the `changeProposal` collection.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The queued (or any-state) proposals on one record.
	 *
	 * @param array<string, string> $subject `register`, `schema` and `id`.
	 * @param string $state The state to list, or '' for every state.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
	 */
	public function forSubject(array $subject, string $state): array {
		$filter = ['subjectSchema' => (string)($subject['schema'] ?? '')];
		if ($state !== '') {
			$filter['state'] = $state;
		}

		return $this->scopedRead(scopeField: 'subjectId', scopeValue: (string)($subject['id'] ?? ''), filter: $filter);
	}//end forSubject()

	/**
	 * Every proposal a proposer made, any state.
	 *
	 * Unlike `forSubject()`, no reviewer permission gates this: the filter IS
	 * the authorization. The caller MUST derive `$proposedBy` from the
	 * subject's own session, never from client input — passing an arbitrary
	 * reference here would turn this into an unscoped read.
	 *
	 * @param string $proposedBy Who proposed it, from the session.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/guardian-self-service-profile/specs/change-proposal-queue/spec.md#requirement-a-proposer-can-list-their-own-proposals-req-cpq-005
	 */
	public function mine(string $proposedBy): array {
		return $this->scopedRead(scopeField: 'proposedBy', scopeValue: $proposedBy);
	}//end mine()

	/**
	 * The rows scoped by one field, re-verified per row after the query.
	 *
	 * @param string $scopeField The field both the query and the per-row check scope by.
	 * @param string $scopeValue The value to scope by; an empty value answers nothing.
	 * @param array<string, mixed> $filter Extra equality filters for the query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scopedRead(string $scopeField, string $scopeValue, array $filter = []): array {
		if ($scopeValue === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: $scopeField,
			subjectRef: $scopeValue,
			organisation: '',
			limit: 200,
			filter: $filter
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row[$scopeField] ?? '') === $scopeValue) {
				$out[] = $row;
			}
		}

		return $out;
	}//end scopedRead()
}//end class
