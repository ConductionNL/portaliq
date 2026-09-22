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
	 * Granting records the decision. It does NOT write a mandate: that is the
	 * owner's separate, deliberate act, so a mis-click never silently opens an
	 * organisation's cases.
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
}//end class
