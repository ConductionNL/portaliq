<?php

/**
 * Portaliq Portal Access Request Service
 *
 * Asking for access you do not have, in the product rather than by mail. The
 * request carries who asked and what for, the owner answers it where they
 * work, and the asker sees the answer. A refusal changes nothing about what
 * the asker can see, which is what makes it safe to let anybody ask.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Records access requests and the answers to them.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalAccessRequestService {
	/**
	 * The register the request lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording a request.
	 */
	private const SCHEMA = 'portalAccessRequest';

	/**
	 * The request was answered as asked.
	 */
	public const OUTCOME_DONE = 'done';

	/**
	 * No request with that id in that organisation.
	 */
	public const OUTCOME_NOT_FOUND = 'not_found';

	/**
	 * The request was already answered.
	 */
	public const OUTCOME_NOT_PENDING = 'not_pending';

	/**
	 * The grant could not record its mandate, so the request stays pending.
	 */
	public const OUTCOME_MANDATE_FAILED = 'mandate_failed';

	/**
	 * The schema recording a mandate.
	 */
	private const MANDATE_SCHEMA = 'portalMandate';

	/**
	 * The label beside a case a granted request opens.
	 */
	private const MANDATE_LABEL = 'Granted on request';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Lists the requests.
	 * @param PortalObjectWriter $writer Records and answers them.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * Ask for access.
	 *
	 * @param string $subjectRef Who asks.
	 * @param string $organisation The tenant whose cases are asked for.
	 * @param string $onBehalfOf The party whose cases are asked for.
	 * @param string $reason What they say they need it for.
	 * @param string $displayName The name the owner sees.
	 *
	 * @return array<string, mixed>|null The recorded request, or null when the
	 *         ask carries neither an identity nor a reason.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function request(string $subjectRef, string $organisation, string $onBehalfOf = '', string $reason = '', string $displayName = ''): ?array {
		if ($subjectRef === '' || $organisation === '' || trim($reason) === '') {
			// A request with no reason cannot be answered by anybody, so it is
			// refused here rather than sitting in somebody's list forever.
			return null;
		}

		return $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			data: [
				'subjectRef' => $subjectRef,
				'displayName' => $displayName,
				'organisation' => $organisation,
				'onBehalfOf' => $onBehalfOf,
				'reason' => $reason,
				'state' => 'pending',
				'requestedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
	}//end request()

	/**
	 * The requests an owner has to answer, for their tenant.
	 *
	 * @param string $organisation The tenant.
	 * @param string $state The state to list, or '' for every state.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function forOwner(string $organisation, string $state = 'pending'): array {
		if ($organisation === '') {
			return [];
		}

		$filter = [];
		if ($state !== '') {
			$filter['state'] = $state;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			limit: 200,
			filter: $filter
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['organisation'] ?? '') === $organisation) {
				$out[] = $row;
			}
		}

		return $out;
	}//end forOwner()

	/**
	 * The requests one asker has made, with the answers they were given.
	 *
	 * @param string $subjectRef The asker.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function madeBy(string $subjectRef): array {
		if ($subjectRef === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: '',
			limit: 200
		);

		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['subjectRef'] ?? '') === $subjectRef) {
				$out[] = $row;
			}
		}

		return $out;
	}//end madeBy()

	/**
	 * Answer a request with a grant or a refusal.
	 *
	 * The bare decision. A grant through the owner's route goes through
	 * `grant()`, which also records the mandate, so a request never reads
	 * `granted` without the access behind it (identity-access-requests D2).
	 *
	 * @param string $id The request's id.
	 * @param string $organisation The tenant, re-checked against the row.
	 * @param bool $granted Whether it is granted.
	 * @param string $decidedBy Who answered.
	 *
	 * @return bool True when the answer landed.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function decide(string $id, string $organisation, bool $granted, string $decidedBy): bool {
		if ($id === '' || $organisation === '' || $decidedBy === '') {
			return false;
		}

		$state = 'refused';
		if ($granted === true) {
			$state = 'granted';
		}

		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			// The tenant is the ownership boundary here: the writer re-reads
			// the row and refuses when it belongs to another organisation.
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			id: $id,
			data: [
				'state' => $state,
				'decidedBy' => $decidedBy,
				'decidedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);

		return $written !== null;
	}//end decide()

	/**
	 * Grant a request and record the mandate it opens, in one act.
	 *
	 * A request never reads `granted` without the mandate behind it: the
	 * mandate is written first, and when it cannot be written the request is
	 * left `pending` and the answer says so (identity-access-requests D2). When
	 * the request can no longer be answered after all, the new mandate is
	 * revoked again, so no access stands without a granted request.
	 *
	 * @param string $id The request's id.
	 * @param string $organisation The tenant, re-checked against the row.
	 * @param string $decidedBy The staff user granting it.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-a-granted-request-opens-the-cases-req-iar-003
	 */
	public function grant(string $id, string $organisation, string $decidedBy): string {
		$request = $this->pendingRequest(id: $id, organisation: $organisation);
		if (is_string($request) === true) {
			return $request;
		}

		if ($decidedBy === '') {
			return self::OUTCOME_NOT_FOUND;
		}

		$mandate = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::MANDATE_SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'subjectRef' => (string)($request['subjectRef'] ?? ''),
				'organisation' => $organisation,
				'onBehalfOf' => (new MandateParties())->typed(value: (string)($request['onBehalfOf'] ?? '')),
				'label' => self::MANDATE_LABEL,
				'reach' => 'organisation',
				'status' => 'active',
				'grantedBy' => $decidedBy,
				'grantedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($mandate === null) {
			return self::OUTCOME_MANDATE_FAILED;
		}

		if ($this->decide(id: $id, organisation: $organisation, granted: true, decidedBy: $decidedBy) === true) {
			return self::OUTCOME_DONE;
		}

		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::MANDATE_SCHEMA,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			id: (string)($mandate['uuid'] ?? $mandate['id'] ?? ''),
			data: ['status' => 'revoked']
		);

		return self::OUTCOME_NOT_FOUND;
	}//end grant()

	/**
	 * Refuse a request, keeping the reason the asker will read.
	 *
	 * @param string $id The request's id.
	 * @param string $organisation The tenant, re-checked against the row.
	 * @param string $reason Why it is refused.
	 * @param string $decidedBy The staff user refusing it.
	 *
	 * @return string One of the OUTCOME_* constants.
	 *
	 * @spec openspec/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
	 */
	public function refuse(string $id, string $organisation, string $reason, string $decidedBy): string {
		if (trim($reason) === '') {
			return self::OUTCOME_NOT_FOUND;
		}

		$request = $this->pendingRequest(id: $id, organisation: $organisation);
		if (is_string($request) === true) {
			return $request;
		}

		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			id: $id,
			data: [
				'state' => 'refused',
				'decisionReason' => trim($reason),
				'decidedBy' => $decidedBy,
				'decidedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($written === null) {
			return self::OUTCOME_NOT_FOUND;
		}

		return self::OUTCOME_DONE;
	}//end refuse()

	/**
	 * The pending request with this id in this organisation, or the outcome
	 * that says why there is none.
	 *
	 * @param string $id The request's id.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|string The row, or an OUTCOME_* constant.
	 */
	private function pendingRequest(string $id, string $organisation): array|string {
		if ($id === '' || $organisation === '') {
			return self::OUTCOME_NOT_FOUND;
		}

		foreach ($this->forOwner(organisation: $organisation, state: '') as $row) {
			$rowId = (string)($row['uuid'] ?? $row['id'] ?? (($row['@self'] ?? [])['id'] ?? ''));
			if ($rowId !== $id) {
				continue;
			}

			if ((string)($row['state'] ?? '') !== 'pending') {
				return self::OUTCOME_NOT_PENDING;
			}

			return $row;
		}

		return self::OUTCOME_NOT_FOUND;
	}//end pendingRequest()
}//end class
