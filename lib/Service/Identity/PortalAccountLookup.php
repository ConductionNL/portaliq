<?php

/**
 * Portaliq Portal Account Lookup
 *
 * The read half of the account space: how an existing `portalAccount` is
 * found, and how carefully. Every finder here re-checks the fields it queried
 * on against the rows that came back, because the reader's scope filter is
 * trusted for the query and not for the answer. A reader that ignored
 * `scopeField` would otherwise hand back the first account on the instance,
 * and the caller would sign the visitor in as somebody else.
 *
 * Split out of PortalAccountService, which now reads through this and keeps
 * the writing. The service's constructor is unchanged, so no caller and no
 * test moved with it.
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
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Finds an existing portal account, three ways.
 *
 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
 */
class PortalAccountLookup {

	/**
	 * The OpenRegister register the `portalAccount` schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The OpenRegister schema recording linked external identities.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * An account provisioned before any login.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * An account a matching first login has activated.
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * A pending account a clerk withdrew. It never matches a login again.
	 */
	public const STATUS_VOID = 'void';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the account rows.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
	) {
	}//end __construct()

	/**
	 * The account a `subjectRef` belongs to, or null.
	 *
	 * @param string $subjectRef The subject reference to look up.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function bySubjectRef(string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			// No organisation filter: a subjectRef is unique across the
			// instance, and requiring the caller to know the tenant first
			// would mean guessing it at the one moment nothing is known yet.
			organisation: '',
			limit: 5
		);

		foreach ($rows as $row) {
			if (($row['subjectRef'] ?? '') === $subjectRef) {
				return $row;
			}
		}

		return null;
	}//end bySubjectRef()

	/**
	 * The account for `(identityType, identityRef, organisation)`, or null.
	 *
	 * Re-verified in memory against ALL three fields: the OpenRegister query
	 * only narrows on `identityRef` plus an `identityType` filter, so the
	 * other two are re-checked here as defence in depth, exactly like the
	 * reader's own `verifyScope()`.
	 *
	 * @param string $identityType One of the register's identityType enum.
	 * @param string $identityRef The IdP's pseudonymous identity reference.
	 * @param string $organisation The tenant slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function byIdentity(string $identityType, string $identityRef, string $organisation): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'identityRef',
			subjectRef: $identityRef,
			organisation: $organisation,
			limit: 5,
			filter: ['identityType' => $identityType]
		);

		foreach ($rows as $row) {
			if (($row['status'] ?? self::STATUS_ACTIVE) === self::STATUS_VOID) {
				// A withdrawn account is not an account. Matching it would
				// hand a login the subjectRef a clerk deliberately retired.
				continue;
			}

			if ($this->matchesIdentity(row: $row, identityType: $identityType, identityRef: $identityRef, organisation: $organisation) === true) {
				return $row;
			}
		}

		return null;
	}//end byIdentity()

	/**
	 * The pending, email-only account for a verified address, or null.
	 *
	 * Only an account with no identity reference of its own is claimable this
	 * way. An account provisioned on an identity reference is matched on that
	 * reference or not at all, so a broker that volunteers somebody else's
	 * address can never reach it.
	 *
	 * @param string $email The address the broker says it verified.
	 * @param string $organisation The tenant slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function pendingByVerifiedEmail(string $email, string $organisation): ?array {
		if ($email === '' || $organisation === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'email',
			subjectRef: $email,
			organisation: $organisation,
			limit: 5,
			filter: ['status' => self::STATUS_PENDING]
		);

		foreach ($rows as $row) {
			if ($this->isClaimablePendingRow(row: $row, email: $email, organisation: $organisation) === true) {
				return $row;
			}
		}

		return null;
	}//end pendingByVerifiedEmail()

	/**
	 * A row's identifier, wherever OpenRegister put it.
	 *
	 * The shape differs by path: flat `uuid`/`id` on some, inside `@self` on
	 * others. A row carrying none cannot be written back to, and says so.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function identifierOf(array $row): ?string {
		$self = ($row['@self'] ?? null);
		$selfUuid = null;
		$selfId = null;
		if (is_array($self) === true) {
			$selfUuid = ($self['uuid'] ?? null);
			$selfId = ($self['id'] ?? null);
		}

		$candidates = [($row['uuid'] ?? null), ($row['id'] ?? null), $selfUuid, $selfId];
		foreach ($candidates as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return null;
	}//end identifierOf()

	/**
	 * Whether a row really is the identity that was queried for.
	 *
	 * @param array<string, mixed> $row One row the reader returned.
	 * @param string $identityType The identity type queried for.
	 * @param string $identityRef The identity reference queried for.
	 * @param string $organisation The tenant queried for.
	 *
	 * @return bool
	 */
	private function matchesIdentity(array $row, string $identityType, string $identityRef, string $organisation): bool {
		return (($row['identityType'] ?? '') === $identityType
			&& ($row['identityRef'] ?? '') === $identityRef
			&& ($row['organisation'] ?? '') === $organisation);
	}//end matchesIdentity()

	/**
	 * Whether a row is a pending account a verified address may claim.
	 *
	 * @param array<string, mixed> $row One row the reader returned.
	 * @param string $email The address queried for.
	 * @param string $organisation The tenant queried for.
	 *
	 * @return bool
	 */
	private function isClaimablePendingRow(array $row, string $email, string $organisation): bool {
		return (($row['email'] ?? '') === $email
			&& ($row['organisation'] ?? '') === $organisation
			&& ($row['status'] ?? '') === self::STATUS_PENDING
			&& ($row['verifiedEmail'] ?? false) === true
			&& ($row['identityRef'] ?? '') === '');
	}//end isClaimablePendingRow()
}//end class
