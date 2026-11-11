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
use OCA\Portaliq\Service\Identity\AccountStatus;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\WaitingAccountJoin;
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
	 * An account provisioned before any login, see AccountStatus::PENDING.
	 */
	public const STATUS_PENDING = AccountStatus::PENDING;

	/**
	 * An account a matching first login has activated, see AccountStatus::ACTIVE.
	 */
	public const STATUS_ACTIVE = AccountStatus::ACTIVE;

	/**
	 * A pending account a clerk withdrew, see AccountStatus::VOID.
	 */
	public const STATUS_VOID = AccountStatus::VOID;

	/**
	 * The `provisionedBy` of a self-registered account, see AccountStatus::SELF_REGISTRATION.
	 */
	public const SELF_REGISTRATION = AccountStatus::SELF_REGISTRATION;

	/**
	 * The lazily built read half, see lookup().
	 *
	 * @var PortalAccountLookup|null
	 */
	private ?PortalAccountLookup $lookup = null;

	/**
	 * The lazily built write half that creates accounts, see creation().
	 *
	 * @var PortalAccountCreation|null
	 */
	private ?PortalAccountCreation $creation = null;

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Looks up an existing account.
	 * @param PortalObjectWriter $writer Creates/updates the account row.
	 * @param ISecureRandom $random Mints a NEW subjectRef on first login.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
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
	 * The same holds for the audience: an existing account's OWN stored
	 * audience wins over the `$audience` the sign-in route proposes (a
	 * provider preset or an organisation's claim map). An app that invited
	 * the person as, say, an `employer` wrote that audience on the account;
	 * the eHerkenning preset's `supplier` must not replace it, or the
	 * session would name an audience no account and no provider answers to.
	 * Only a NEW account takes the proposed audience.
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
	 * @return array{subjectRef: string, isNew: bool, audience: string}|null Null when OpenRegister
	 *                                                     is unavailable or the
	 *                                                     write failed (fail closed
	 *                                                     — the caller mints no session).
	 *
	 * @spec openspec/changes/portal-oidc-broker-login/tasks.md#T08
	 * @spec openspec/specs/supplier-portal/spec.md#the-subject-reference-is-server-derived-never-client-supplied
	 * @spec openspec/changes/portal-invitation-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 * @spec openspec/changes/the-account-names-the-audience-and-the-company/specs/portal-identity-space/spec.md#requirement-an-existing-accounts-audience-wins-over-the-sign-in-routes
	 */
	public function findOrCreate(
		string $identityType,
		string $identityRef,
		string $organisation,
		string $audience,
		?string $subjectRefOverride = null,
		string $verifiedEmail = '',
	): ?array {
		$run = new AccountLoginRun(identityType: $identityType, identityRef: $identityRef);
		$run->organisation  = $organisation;
		$run->audience      = $audience;
		$run->verifiedEmail = $verifiedEmail;
		if ($run->isComplete() === false) {
			return null;
		}

		$existing = $this->existingFor(run: $run);
		if ($existing !== null) {
			return $this->returningAccount(run: $run, existing: $existing);
		}

		return $this->newAccount(run: $run, subjectRef: ($subjectRefOverride ?? $this->creation()->mintSubjectRef()));
	}//end findOrCreate()

	/**
	 * The matched account, activated and stamped, with its own subjectRef
	 * and audience: the stored audience first, the proposed one when none is
	 * stored.
	 *
	 * @param AccountLoginRun      $run      The sign-in.
	 * @param array<string, mixed> $existing The matched account row.
	 *
	 * @return array{subjectRef: string, isNew: bool, audience: string}
	 */
	private function returningAccount(AccountLoginRun $run, array $existing): array {
		$this->activate(existing: $existing, run: $run);
		// The stored audience first, the proposed one when none is stored.
		$audiences = array_filter([trim((string)($existing['audience'] ?? '')), $run->audience], static fn (string $value): bool => $value !== '');

		return ['subjectRef' => (string)($existing['subjectRef'] ?? ''), 'isNew' => false, 'audience' => (string)reset($audiences)];
	}//end returningAccount()

	/**
	 * Create the active account for a new identity under the subjectRef
	 * given; null when there is none or the write fails.
	 *
	 * @param AccountLoginRun $run        The sign-in.
	 * @param string          $subjectRef The validated-claim or freshly minted subjectRef.
	 *
	 * @return array{subjectRef: string, isNew: bool, audience: string}|null
	 */
	private function newAccount(AccountLoginRun $run, string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$created = $this->creation()->createRow(organisation: $run->organisation, data: $this->newAccountRow(run: $run, subjectRef: $subjectRef));
		if ($created === null) {
			return null;
		}

		return ['subjectRef' => $subjectRef, 'isNew' => true, 'audience' => $run->audience];
	}//end newAccount()

	/**
	 * The row of a new identity's account: active, stamped with this login.
	 *
	 * @param AccountLoginRun $run        The sign-in.
	 * @param string          $subjectRef The account's subjectRef.
	 *
	 * @return array<string, mixed>
	 */
	private function newAccountRow(AccountLoginRun $run, string $subjectRef): array {
		return [
			'audience' => $run->audience,
			'identityType' => $run->identityType,
			'identityRef' => $run->identityRef,
			'subjectRef' => $subjectRef,
			'organisation' => $run->organisation,
			'status' => 'active',
			'lastLoginAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];
	}//end newAccountRow()

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

		$claims = (array)($account['claims'] ?? []);
		$appClaims = (array)($claims[$appId] ?? []);
		$appClaims[$claimName] = $value;
		$claims[$appId] = $appClaims;

		return $this->update(account: $account, data: ['claims' => $claims]);
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

		return $this->update(
			account: $account,
			data: [
				'status' => self::STATUS_VOID,
				'voidReason' => $reason,
				'provisionedBy' => (string)($account['provisionedBy'] ?? $voidedBy),
			]
		);
	}//end voidPending()


	/**
	 * Approve a self-registration that waits for a decision (REQ-ISA-004).
	 *
	 * Only a pending account the registrant created is approved here: a
	 * pending account a clerk provisioned becomes active on its owner's first
	 * sign-in, not by a second clerk's click. `verifiedEmail` stays as the
	 * registrant left it.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return bool True when the account is now active.
	 *
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
	 */
	public function approvePending(string $subjectRef): bool {
		$account = $this->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null
			|| ($account['status'] ?? '') !== self::STATUS_PENDING
			|| ($account['provisionedBy'] ?? '') !== self::SELF_REGISTRATION
		) {
			return false;
		}

		return $this->update(account: $account, data: ['status' => self::STATUS_ACTIVE]);
	}//end approvePending()

	/**
	 * Activate the matched account and stamp the login (REQ-PIS-002).
	 *
	 * A pending account becomes active on the login that matched it, keeping
	 * its own `subjectRef` so every claim written before the login still
	 * points at the person who just arrived. An already active account is
	 * only stamped.
	 *
	 * @param array<string, mixed> $existing The matched account row.
	 * @param AccountLoginRun      $run      The sign-in, with the identity it carried.
	 *
	 * @return void
	 */
	private function activate(array $existing, AccountLoginRun $run): void {
		if (($existing['status'] ?? self::STATUS_ACTIVE) !== self::STATUS_PENDING) {
			$this->touchLastLogin(existing: $existing);
			return;
		}

		$this->update(
			account: $existing,
			data: [
				'status' => self::STATUS_ACTIVE,
				'identityType' => $run->identityType,
				'identityRef' => $run->identityRef,
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
		$this->update(account: $existing, data: ['lastLoginAt' => (new DateTimeImmutable())->format(DATE_ATOM)]);
	}//end touchLastLogin()

	/**
	 * Write `$data` onto an account row this service itself already located.
	 *
	 * Internal, privileged update: an empty scopeField/subjectRef/organisation
	 * skips the writer's ownership re-check, mirroring
	 * PortalSessionService::revokeQuietly()'s identical rationale.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param array<string, mixed> $data    The fields to write.
	 *
	 * @return bool False when the row has no identifier or the write failed.
	 */
	private function update(array $account, array $data): bool {
		$uuid = $this->lookup()->identifierOf(row: $account);
		if ($uuid === null) {
			return false;
		}

		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $uuid,
			data: $data
		) !== null;
	}//end update()

	/**
	 * The account a sign-in matches, or null for a new one.
	 *
	 * First on the identity reference. A person who signed in before an app
	 * provisioned a waiting account for their verified address would never
	 * reach that account, so its claims join the account found here
	 * (portal-invitation-joins-the-signed-in-account). Then, and only then,
	 * on the verified address (REQ-PIS-002 second pass): an account
	 * provisioned on an identity reference is matched on that reference or
	 * not at all, so a broker that volunteers somebody else's address can
	 * never reach it. Only an email-only pending account, whose address was
	 * verified out of band, is claimable this way.
	 *
	 * @param AccountLoginRun $run The sign-in.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/portal-identity-space/spec.md#requirement-first-login-matches-the-pending-account-req-pis-002
	 * @spec openspec/changes/portal-invitation-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	private function existingFor(AccountLoginRun $run): ?array {
		$existing = $this->lookup()->byIdentity(identityType: $run->identityType, identityRef: $run->identityRef, organisation: $run->organisation);
		if ($existing === null) {
			// An empty address finds nothing here.
			return $this->lookup()->pendingByVerifiedEmail(email: $run->verifiedEmail, organisation: $run->organisation);
		}

		(new WaitingAccountJoin(lookup: $this->lookup(), writer: $this->writer))->join(
			account: $existing,
			verifiedEmail: $run->verifiedEmail,
			organisation: $run->organisation
		);

		return $existing;
	}//end existingFor()

	/**
	 * The write half that creates accounts: it mints a new account's
	 * subjectRef and writes its row, for a sign-in here and for a
	 * provisioning there.
	 *
	 * Built from this service's own collaborators rather than injected, so
	 * the constructor is unchanged and the same writer and generator serve
	 * both.
	 *
	 * @return PortalAccountCreation
	 *
	 * @spec openspec/changes/portal-identity-space/specs/portal-identity-space/spec.md
	 */
	private function creation(): PortalAccountCreation {
		if ($this->creation === null) {
			$this->creation = new PortalAccountCreation(reader: $this->reader, writer: $this->writer, random: $this->random);
		}

		return $this->creation;
	}//end creation()

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
