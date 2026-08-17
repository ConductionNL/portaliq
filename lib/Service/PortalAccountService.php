<?php

/**
 * Portaliq Portal Account Service
 *
 * Find-or-create for `portalAccount`, keyed on `(identityType, identityRef,
 * organisation)` — the OIDC broker login edge's identity resolution
 * (portal-oidc-broker-login). `subjectRef` is either reused from an existing
 * account or minted ONCE, server-side, via a cryptographically secure random
 * generator — it is NEVER accepted from a request parameter (design.md,
 * contract-v2 IDOR discipline), matching every other `subjectRef` mint in
 * this app ({@see PortalSessionService}).
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T08
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use DateTimeImmutable;
use OCP\Security\ISecureRandom;

/**
 * Find-or-create `portalAccount` for the OIDC login edge.
 *
 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T08
 */
class PortalAccountService {
	/**
	 * The OpenRegister register the `portalAccount` schema lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The OpenRegister schema recording linked external identities.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Looks up an existing account.
	 * @param PortalObjectWriter $writer Creates/updates the account row.
	 * @param ISecureRandom $random Mints a NEW subjectRef on first login.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Find-or-create the `portalAccount` for a validated external identity.
	 *
	 * Looks the account up by `(identityType, identityRef, organisation)`
	 * first — an existing account's OWN `subjectRef` always wins, regardless
	 * of `$subjectRefOverride`, so a stable identity never gets a second,
	 * colliding subjectRef on a later login. Only a genuinely NEW account
	 * uses `$subjectRefOverride` (a validated-claim value) when supplied, or
	 * mints a fresh cryptographically random one otherwise.
	 *
	 * @param string $identityType One of the register's identityType enum.
	 * @param string $identityRef The IdP's pseudonymous identity reference.
	 * @param string $organisation The tenant slug.
	 * @param string $audience The external audience ("supplier"|"client"|...).
	 * @param string|null $subjectRefOverride A validated-claim subjectRef for a
	 *                                        NEW account (null = mint one server-side).
	 *
	 * @return array{subjectRef: string, isNew: bool}|null Null when OpenRegister
	 *                                                     is unavailable or the
	 *                                                     write failed (fail closed
	 *                                                     — the caller mints no session).
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T08
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 */
	public function findOrCreate(
		string $identityType,
		string $identityRef,
		string $organisation,
		string $audience,
		?string $subjectRefOverride = null,
	): ?array {
		if ($identityType === '' || $identityRef === '' || $organisation === '') {
			return null;
		}

		$existing = $this->findExisting(identityType: $identityType, identityRef: $identityRef, organisation: $organisation);
		if ($existing !== null) {
			$this->touchLastLogin(existing: $existing);
			return ['subjectRef' => (string)($existing['subjectRef'] ?? ''), 'isNew' => false];
		}

		$subjectRef = ($subjectRefOverride ?? $this->mintSubjectRef());
		if ($subjectRef === '') {
			return null;
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'audience' => $audience,
				'identityType' => $identityType,
				'identityRef' => $identityRef,
				'subjectRef' => $subjectRef,
				'organisation' => $organisation,
				'status' => 'active',
				'lastLoginAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		return ['subjectRef' => $subjectRef, 'isNew' => true];
	}//end findOrCreate()

	/**
	 * Find the existing `portalAccount` for `(identityType, identityRef,
	 * organisation)`, re-verified in-memory against ALL three fields — the
	 * OR query only narrows on `identityRef` (+ `identityType` filter);
	 * `identityType` and `organisation` are re-checked here as defence in
	 * depth, exactly like the reader's own `verifyScope()`.
	 *
	 * @param string $identityType One of the register's identityType enum.
	 * @param string $identityRef The IdP's pseudonymous identity reference.
	 * @param string $organisation The tenant slug.
	 *
	 * @return array<string, mixed>|null
	 */
	/**
	 * The account a `subjectRef` belongs to, or null.
	 *
	 * Used by the `nextcloud` sign-in mode, where the Nextcloud user id IS the
	 * subjectRef. It looks up rather than creates on purpose: minting an
	 * account here would make every user on the instance a citizen of every
	 * portal that enables the mode.
	 *
	 * @param string $subjectRef The subject reference to look up.
	 *
	 * @return array<string, mixed>|null The account, or null when there is none.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
	 */
	public function findBySubjectRef(string $subjectRef): ?array {
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
			// The scope filter is trusted for the query, not for the answer: a
			// reader that ignored `scopeField` would otherwise hand back the
			// first account on the instance and this method would sign the
			// visitor in as somebody else.
			if (($row['subjectRef'] ?? '') === $subjectRef) {
				return $row;
			}
		}

		return null;
	}//end findBySubjectRef()


	/**
	 * The account for one external identity within one organisation, or null.
	 *
	 * Matched on all three of `identityType`, `identityRef` and `organisation`:
	 * the same identity reference issued by two different providers, or held in
	 * two tenants, is two different accounts.
	 *
	 * @param string $identityType The identity provider type.
	 * @param string $identityRef The provider's reference for the identity.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null The account, or null when there is none.
	 */
	private function findExisting(string $identityType, string $identityRef, string $organisation): ?array {
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
			if (($row['identityType'] ?? '') === $identityType
				&& ($row['identityRef'] ?? '') === $identityRef
				&& ($row['organisation'] ?? '') === $organisation
			) {
				return $row;
			}
		}

		return null;
	}//end findExisting()

	/**
	 * Stamp `lastLoginAt` on an existing account. Best-effort — a failure to
	 * record the timestamp must never block the login itself, so this never
	 * affects `findOrCreate()`'s return value.
	 *
	 * @param array<string, mixed> $existing The existing account row.
	 *
	 * @return void
	 */
	private function touchLastLogin(array $existing): void {
		$uuid = $this->rowId(row: $existing);
		if ($uuid === null) {
			return;
		}

		// Internal, privileged update of a row this service itself already
		// located and verified — an empty scopeField/subjectRef/organisation
		// skips the writer's ownership re-check, mirroring
		// PortalSessionService::revokeQuietly()'s identical rationale.
		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: ['lastLoginAt' => (new DateTimeImmutable())->format(DATE_ATOM)]
		);
	}//end touchLastLogin()

	/**
	 * Mint a fresh, cryptographically random subjectRef for a brand-new account.
	 *
	 * @return string
	 */
	private function mintSubjectRef(): string {
		return $this->random->generate(32, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
	}//end mintSubjectRef()

	/**
	 * Extract a row's identifier (`id`/`uuid`, flat or in `@self`), or null.
	 *
	 * @param array<string, mixed> $row The normalised row.
	 *
	 * @return string|null
	 */
	private function rowId(array $row): ?string {
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
	}//end rowId()
}//end class
