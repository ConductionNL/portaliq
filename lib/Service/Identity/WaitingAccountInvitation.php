<?php

/**
 * Portaliq Waiting Account Invitation
 *
 * An app provisions a waiting account for a person it knows (a school and a
 * guardian). The person reaches it when their sign-in carries the same
 * verified address. A sign-in that carries no address (the integriq broker's
 * DigiD answer) never does. This gives the waiting account a one-time
 * secret: the person receives it, signs in any way the portal offers, and
 * hands it back, and the waiting account joins the account they signed in
 * with.
 *
 * Only the hash of the secret is stored, it expires, and it works once.
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
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Issues and redeems the one-time secret of a waiting account.
 *
 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class WaitingAccountInvitation {
	/**
	 * How long the secret works: the week an invitation into the portal has.
	 */
	public const TTL = 'P7D';

	/**
	 * The secret was right: the waiting account joined the caller's account.
	 */
	public const CLAIMED = 'claimed';

	/**
	 * Wrong, expired and already used, told apart by nobody.
	 */
	public const NOT_VALID = 'not_valid';

	/**
	 * The caller offered too many wrong secrets.
	 */
	public const LOCKED = 'locked';

	/**
	 * The reason written on the waiting account once it is joined.
	 */
	public const VOID_REASON = 'Joined the account that redeemed its invitation';

	/**
	 * The register the accounts live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Finds the account a secret belongs to.
	 * @param PortalObjectWriter $writer Stores the hash and spends it.
	 * @param ISecureRandom $random Mints the secret.
	 * @param ClaimAttempts $attempts Limits the wrong secrets one person may offer.
	 * @param AuditTrailService $auditor Records who joined which account.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
		private readonly ClaimAttempts $attempts,
		private readonly AuditTrailService $auditor,
	) {
	}//end __construct()

	/**
	 * Mint the secret of a waiting account, for the mail.
	 *
	 * Only the app that provisioned the account may ask, and only while the
	 * account is still waiting: pending, with no identity reference of its
	 * own, and with an address to mail. Asking again replaces the earlier
	 * secret, so a lost mail is answered by inviting once more.
	 *
	 * @param string $subjectRef The waiting account.
	 * @param string $appId The app asking, from its own dispatching context.
	 * @param DateTimeImmutable|null $now The moment the secret is dated from.
	 *
	 * @return array{token: string, email: string, organisation: string, expiresAt: string}|null
	 *         Null when the account is not this app's waiting account or the write failed.
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function issue(string $subjectRef, string $appId, ?DateTimeImmutable $now = null): ?array {
		$lookup  = new PortalAccountLookup(reader: $this->reader);
		$account = $lookup->bySubjectRef(subjectRef: $subjectRef);
		if ($account === null || $appId === '' || $this->isInvitable(account: $account, appId: $appId) === false) {
			return null;
		}

		$uuid  = $lookup->identifierOf(row: $account);
		$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		if ($uuid === null || $token === '') {
			return null;
		}

		$expiresAt = ($now ?? new DateTimeImmutable())->add(new DateInterval(self::TTL))->format(DATE_ATOM);
		$written   = $this->write(id: $uuid, data: ['claimTokenHash' => hash('sha256', $token), 'claimExpiresAt' => $expiresAt]);
		if ($written === false) {
			return null;
		}

		return [
			'token' => $token,
			'email' => (string)$account['email'],
			'organisation' => (string)($account['organisation'] ?? ''),
			'expiresAt' => $expiresAt,
		];
	}//end issue()

	/**
	 * Redeem a secret for the person who is signed in.
	 *
	 * The caller's own account must be active and signed in through an
	 * identity provider. The secret must belong to a waiting account in the
	 * session's organisation that is still pending, has no identity reference
	 * and has not expired. The secret is spent before the join, so it can
	 * never work twice, and a wrong one counts against the caller.
	 *
	 * @param array<string, mixed> $subject The session, as PortalSessionService resolves it.
	 * @param string $secret The secret the person hands back.
	 * @param DateTimeImmutable|null $now The moment to judge expiry and the lock against.
	 *
	 * @return string One of CLAIMED, NOT_VALID and LOCKED.
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function redeem(array $subject, string $secret, ?DateTimeImmutable $now = null): string {
		$moment  = ($now ?? new DateTimeImmutable());
		$lookup  = new PortalAccountLookup(reader: $this->reader);
		$account = $this->receiver(subject: $subject, lookup: $lookup);
		if ($account === null) {
			return self::NOT_VALID;
		}

		$accountId = (string)$lookup->identifierOf(row: $account);
		$jti       = (string)($subject['jti'] ?? '');
		if ($this->attempts->locked(account: $account, jti: $jti, now: $moment) === true) {
			return self::LOCKED;
		}

		$waiting = $this->waitingFor(secret: trim($secret), organisation: (string)$account['organisation'], moment: $moment);
		if ($waiting === null) {
			$this->attempts->fail(account: $account, accountId: $accountId, jti: $jti, now: $moment);
			return self::NOT_VALID;
		}

		$joined = $this->spendAndJoin(account: $account, waiting: $waiting, lookup: $lookup);
		if ($joined === null) {
			return self::NOT_VALID;
		}

		$this->attempts->clear(account: $account, accountId: $accountId);
		// Who took over which waiting account, in which session, and when.
		$this->auditor->record(
			verb: ConfirmedAddressJoin::AUDIT_VERB,
			subjectRef: (string)$account['subjectRef'],
			organisation: (string)$account['organisation'],
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: $joined,
			jti: $jti
		);

		return self::CLAIMED;
	}//end redeem()

	/**
	 * The session's own account, when it may take over a waiting account:
	 * active, in the session's organisation, signed in through an identity
	 * provider, and a row that can be written to.
	 *
	 * @param array<string, mixed> $subject The session.
	 * @param PortalAccountLookup $lookup The account finder.
	 *
	 * @return array<string, mixed>|null
	 */
	private function receiver(array $subject, PortalAccountLookup $lookup): ?array {
		$account = $lookup->bySubjectRef(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if ($account === null || $lookup->identifierOf(row: $account) === null) {
			return null;
		}

		$organisation = (string)($account['organisation'] ?? '');
		$receives     = ($account['status'] ?? '') === PortalAccountService::STATUS_ACTIVE
			&& (string)($account['identityRef'] ?? '') !== ''
			&& $organisation !== ''
			&& $organisation === (string)($subject['organisation'] ?? '');
		if ($receives === false) {
			return null;
		}

		return $account;
	}//end receiver()

	/**
	 * Spend the secret, then join the waiting account into the caller's.
	 *
	 * Spent first. If the join then fails the invitation is dead and the app
	 * invites again: a secret that worked twice would be worse.
	 *
	 * @param array<string, mixed> $account The caller's account.
	 * @param array<string, mixed> $waiting The waiting account the secret opened.
	 * @param PortalAccountLookup $lookup The account finder.
	 *
	 * @return string|null The identifier of the waiting account that joined, or null.
	 */
	private function spendAndJoin(array $account, array $waiting, PortalAccountLookup $lookup): ?string {
		$waitingId = $lookup->identifierOf(row: $waiting);
		if ($waitingId === null || $this->write(id: $waitingId, data: ['claimTokenHash' => '']) === false) {
			return null;
		}

		$join = new WaitingAccountJoin(lookup: $lookup, writer: $this->writer);

		return $join->joinWaiting(account: $account, waiting: $waiting, reason: self::VOID_REASON);
	}//end spendAndJoin()

	/**
	 * Whether an account is one this app may send an invitation for.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param string $appId The app asking.
	 *
	 * @return bool
	 */
	private function isInvitable(array $account, string $appId): bool {
		return ($account['status'] ?? '') === PortalAccountService::STATUS_PENDING
			&& (string)($account['identityRef'] ?? '') === ''
			&& (string)($account['provisionedBy'] ?? '') === $appId
			&& trim((string)($account['email'] ?? '')) !== '';
	}//end isInvitable()

	/**
	 * The waiting account a secret opens in one organisation, or null.
	 *
	 * Unknown, spent, expired, another organisation's and an account that is
	 * no longer waiting all answer null.
	 *
	 * @param string $secret The secret.
	 * @param string $organisation The session's organisation.
	 * @param DateTimeImmutable $moment The moment to judge expiry against.
	 *
	 * @return array<string, mixed>|null
	 */
	private function waitingFor(string $secret, string $organisation, DateTimeImmutable $moment): ?array {
		if ($secret === '') {
			return null;
		}

		$hash = hash('sha256', $secret);
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'claimTokenHash',
			subjectRef: $hash,
			organisation: $organisation,
			limit: 5
		);

		foreach ($rows as $row) {
			// The reader's filter is trusted for the query, not for the
			// answer: every row is checked again.
			if (is_array($row) === false || $this->isWaitingRow(row: $row, hash: $hash, organisation: $organisation) === false) {
				continue;
			}

			$expiry = date_create_immutable((string)($row['claimExpiresAt'] ?? ''));
			if ($expiry === false || $expiry <= $moment) {
				return null;
			}

			return $row;
		}

		return null;
	}//end waitingFor()

	/**
	 * Whether a row is the waiting account a secret's hash opens: the hash
	 * matches, it sits in the organisation, it is pending and it has no
	 * identity reference of its own.
	 *
	 * @param array<string, mixed> $row One row the reader returned.
	 * @param string $hash The secret's hash.
	 * @param string $organisation The session's organisation.
	 *
	 * @return bool
	 */
	private function isWaitingRow(array $row, string $hash, string $organisation): bool {
		return hash_equals((string)($row['claimTokenHash'] ?? ''), $hash) === true
			&& ($row['organisation'] ?? '') === $organisation
			&& ($row['status'] ?? '') === PortalAccountService::STATUS_PENDING
			&& (string)($row['identityRef'] ?? '') === '';
	}//end isWaitingRow()

	/**
	 * One internal update of an account row this class itself located.
	 *
	 * @param string $id The row's identifier.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return bool True when the write landed.
	 */
	private function write(string $id, array $data): bool {
		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: $data
		) !== null;
	}//end write()
}//end class
