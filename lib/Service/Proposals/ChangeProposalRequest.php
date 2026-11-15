<?php

/**
 * Portaliq Change Proposal Request
 *
 * One request to queue a proposal against a record: the record, the changes
 * asked for, the properties the contribution allows, the record as it stands,
 * who proposes it, through which channel, and what they said.
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

/**
 * A proposal someone asks to queue.
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */
final class ChangeProposalRequest {
	/**
	 * Constructor.
	 *
	 * @param array<string, string> $subject    `register`, `schema` and `id`.
	 * @param array<int, mixed>                $changes    Each `property` and `proposedValue`.
	 * @param array<int, string>               $proposable The properties the contribution allows.
	 * @param array<string, mixed>             $subjectRow The record as it stands, for the snapshot.
	 * @param string                           $proposedBy Who proposed it, from the session.
	 * @param string                           $channel    `portal` or `staff`.
	 * @param string                           $note       What the proposer said.
	 */
	public function __construct(
		public readonly array $subject,
		public readonly array $changes,
		public readonly array $proposable,
		public readonly array $subjectRow,
		public readonly string $proposedBy,
		public readonly string $channel = 'portal',
		public readonly string $note = '',
	) {
	}//end __construct()
}//end class
