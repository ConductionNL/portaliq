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
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
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
	 * An account provisioned before any login: no session, no reach.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
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
	 * The lazily built read half, see lookup().
	 *
	 * @var PortalAccountLookup|null
	 */
	private ?PortalAccountLookup $lookup = null;

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
	 * @param string $verifiedEmail An address the broker itself says it
	 *                              verified, used only to claim a pending
	 *                              account (empty = no second pass).
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
		string $verifiedEmail = '',
	): ?array {
		if ($identityType === '' || $identityRef === '' || $organisation === '') {
			return null;
		}

		$existing = $this->lookup()->byIdentity(identityType: $identityType, identityRef: $identityRef, organisation: $organisation);
		if ($existing === null && $verifiedEmail !== '') {
			// REQ-PIS-002 second pass, and only a second pass: an account
			// provisioned on an identity reference is matched on that
			// reference or not at all, so a broker that volunteers somebody
			// else's address can never reach it. Only an email-only pending
			// account, whose address was verified out of band, is claimable
			// this way.
			$existing = $this->lookup()->pendingByVerifiedEmail(email: $verifiedEmail, organisation: $organisation);
		}

		if ($existing !== null) {
			$this->activate(existing: $existing, identityType: $identityType, identityRef: $identityRef);
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
		return $this->lookup()->bySubjectRef(subjectRef: $subjectRef);
	}//end findBySubjectRef()


	/**
	 * Provision an account before any login (REQ-PIS-001).
	 *
	 * The account is created `pending`: it has no session and is unreachable
	 * from the portal until a first login matches it. A call with neither an
	 * identity reference nor an email is refused, because such a row could
	 * never be matched by anything and would only be a dangling subjectRef.
	 *
	 * @param string $audience The external audience ("client"|"supplier"|...).
	 * @param string $organisation The tenant slug.
	 * @param string $identityType One of the register's identityType enum, or ''.
	 * @param string $identityRef The identity reference, or '' when unknown.
	 * @param string $email A contact address, or '' when none is known.
	 * @param bool $verifiedEmail True when that address was verified out of band.
	 * @param string $provisionedBy The staff user id or app id that asked.
	 * @param string $displayName The name to greet the person by, or ''.
	 *
	 * @return array{subjectRef: string, isNew: bool, status: string}|null Null
	 *         when the call is refused or OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) -- one parameter per
	 * declared field of the provisioned row; an options array would lose the
	 * type safety on the identity boundary.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- `verifiedEmail` is a
	 * field OF the account, not a mode this method runs in. It is carried to
	 * the stored row and nothing ever branches on it: one write, no condition.
	 * Splitting the method in two would give two methods differing in a literal.
	 */
	public function provision(
		string $audience,
		string $organisation,
		string $identityType = '',
		string $identityRef = '',
		string $email = '',
		bool $verifiedEmail = false,
		string $provisionedBy = '',
		string $displayName = '',
	): ?array {
		if ($audience === '' || $organisation === '') {
			return null;
		}

		$hasIdentity = ($identityType !== '' && $identityRef !== '');
		if ($hasIdentity === false && $email === '') {
			return null;
		}

		$existing = $this->accountAlreadyProvisioned(
			identityType: $identityType,
			identityRef: $identityRef,
			email: $email,
			organisation: $organisation
		);
		if ($existing !== null) {
			return [
				'subjectRef' => (string)($existing['subjectRef'] ?? ''),
				'isNew' => false,
				'status' => (string)($existing['status'] ?? self::STATUS_ACTIVE),
			];
		}

		$subjectRef = $this->mintSubjectRef();
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
				'displayName' => $displayName,
				'email' => $email,
				'verifiedEmail' => $verifiedEmail,
				'status' => self::STATUS_PENDING,
				'provisionedBy' => $provisionedBy,
				'provisionedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		return ['subjectRef' => $subjectRef, 'isNew' => true, 'status' => self::STATUS_PENDING];
	}//end provision()

	/**
	 * Write an app's claim on an account, server-side (REQ-PIS-003).
	 *
	 * The app id comes from the dispatching context, never from the payload,
	 * so one app can never write under another's name.
	 *
	 * @param string $subjectRef The account to write on.
	 * @param string $appId The dispatching app.
	 * @param string $claimName The claim to set.
	 * @param string $value The claim value.
	 *
	 * @return bool True when the claim landed.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function claim(string $subjectRef, string $appId, string $claimName, string $value): bool {
		if ($subjectRef === '' || $appId === '' || $claimName === '' || $value === '') {
			return false;
		}

		$account = $this->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null) {
			return false;
		}

		$uuid = $this->lookup()->identifierOf(row: $account);
		if ($uuid === null) {
			return false;
		}

		$claims = (array)($account['claims'] ?? []);
		$appClaims = (array)($claims[$appId] ?? []);
		$appClaims[$claimName] = $value;
		$claims[$appId] = $appClaims;

		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: ['claims' => $claims]
		);

		return $written !== null;
	}//end claim()

	/**
	 * Withdraw a pending account, with the reason on the row (D6).
	 *
	 * Only a `pending` account can be voided: an active account is somebody's
	 * live session and is suspended, not erased by another name.
	 *
	 * @param string $subjectRef The account to withdraw.
	 * @param string $reason Why it was withdrawn.
	 * @param string $voidedBy The staff user id that withdrew it.
	 *
	 * @return bool True when the account is now void.
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	public function voidPending(string $subjectRef, string $reason, string $voidedBy = ''): bool {
		if ($subjectRef === '' || $reason === '') {
			return false;
		}

		$account = $this->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null || ($account['status'] ?? '') !== self::STATUS_PENDING) {
			return false;
		}

		$uuid = $this->lookup()->identifierOf(row: $account);
		if ($uuid === null) {
			return false;
		}

		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: [
				'status' => self::STATUS_VOID,
				'voidReason' => $reason,
				'provisionedBy' => (string)($account['provisionedBy'] ?? $voidedBy),
			]
		);

		return $written !== null;
	}//end voidPending()


	/**
	 * Activate the matched account and stamp the login (REQ-PIS-002).
	 *
	 * A pending account becomes active on the login that matched it, keeping
	 * its own `subjectRef` so every claim written before the login still
	 * points at the person who just arrived. An already active account is
	 * only stamped.
	 *
	 * @param array<string, mixed> $existing The matched account row.
	 * @param string $identityType The identity type the login carried.
	 * @param string $identityRef The identity reference the login carried.
	 *
	 * @return void
	 */
	private function activate(array $existing, string $identityType, string $identityRef): void {
		if (($existing['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_PENDING) {
			$this->touchLastLogin(existing: $existing);
			return;
		}

		$uuid = $this->lookup()->identifierOf(row: $existing);
		if ($uuid === null) {
			return;
		}

		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: [
				'status' => self::STATUS_ACTIVE,
				'identityType' => $identityType,
				'identityRef' => $identityRef,
				'lastLoginAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
	}//end activate()

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
		$uuid = $this->lookup()->identifierOf(row: $existing);
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
	 * The account this provision call would duplicate, or null.
	 *
	 * An identity reference is matched on that reference. A call with no
	 * identity reference falls back to a pending, email-only account, which
	 * is the only kind an address alone may claim.
	 *
	 * @param string $identityType The identity type, or ''.
	 * @param string $identityRef The identity reference, or ''.
	 * @param string $email The contact address, or ''.
	 * @param string $organisation The tenant slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	private function accountAlreadyProvisioned(string $identityType, string $identityRef, string $email, string $organisation): ?array {
		if ($identityType !== '' && $identityRef !== '') {
			return $this->lookup()->byIdentity(identityType: $identityType, identityRef: $identityRef, organisation: $organisation);
		}

		return $this->lookup()->pendingByVerifiedEmail(email: $email, organisation: $organisation);
	}//end accountAlreadyProvisioned()

	/**
	 * The read half of the account space.
	 *
	 * Built from this service's own reader rather than injected, so the
	 * constructor is unchanged and no caller or test had to move when the
	 * finders were split out.
	 *
	 * @return PortalAccountLookup
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	private function lookup(): PortalAccountLookup {
		if ($this->lookup === null) {
			$this->lookup = new PortalAccountLookup(reader: $this->reader);
		}

		return $this->lookup;
	}//end lookup()
}//end class
