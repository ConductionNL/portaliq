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
	 * The caller's own account cannot take over a waiting account: it is not
	 * active, did not sign in through an identity provider, or sits in
	 * another organisation. About the caller, never about the invitation,
	 * and nothing is spent or counted (security review L6).
	 */
	public const CANNOT_RECEIVE = 'cannot_receive';

	/**
	 * The secret is right, but the waiting account carries a claim the
	 * caller's account holds with another value. Nothing is spent, so the
	 * invitation stays with its real holder (security review M1).
	 */
	public const CONFLICT = 'conflict';

	/**
	 * Another request holds the lock of the caller's or the waiting account
	 * past a short wait. Nothing changed; trying again is safe.
	 */
	public const BUSY = 'busy';

	/**
	 * The reason written on the waiting account once it is joined.
	 */
	public const VOID_REASON = 'Joined the account that redeemed its invitation';

	/**
	 * The audit verb of an account taking over a waiting account's claims,
	 * the one ConfirmedAddressJoin records as well.
	 */
	private const AUDIT_VERB = 'claim';

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
	 * @param ClaimLock $lock Makes the read and the write of a redeem one step.
	 * @param WaitingAccountSecret $secrets Finds the waiting account a secret opens, and hashes a code.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
		private readonly ClaimAttempts $attempts,
		private readonly AuditTrailService $auditor,
		private readonly ClaimLock $lock,
		private readonly WaitingAccountSecret $secrets,
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
		// One live secret at a time: a new link ends an earlier code.
		$written = $this->write(id: $uuid, data: ['claimTokenHash' => hash('sha256', $token), 'claimCodeHash' => '', 'claimExpiresAt' => $expiresAt]);
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
	 * Mint the short code of a waiting account, for a paper letter.
	 *
	 * The same secret in a form a person can type. Only the hash is stored,
	 * with the same expiry as a link, and it replaces an earlier link or
	 * code. The code is answered to the app that asked, because somebody has
	 * to print it: this is the one place the secret is seen by staff.
	 *
	 * @param string $subjectRef The waiting account.
	 * @param string $appId The app asking, from its own dispatching context.
	 * @param DateTimeImmutable|null $now The moment the code is dated from.
	 *
	 * @return array{code: string, expiresAt: string}|null Null when the
	 *         account is not this app's waiting account or the write failed.
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function issueCode(string $subjectRef, string $appId, ?DateTimeImmutable $now = null): ?array {
		$lookup  = new PortalAccountLookup(reader: $this->reader);
		$account = $lookup->bySubjectRef(subjectRef: $subjectRef);
		if ($account === null || $appId === '' || $this->isInvitable(account: $account, appId: $appId, byMail: false) === false) {
			return null;
		}

		$codes = new InvitationCode();
		$uuid  = $lookup->identifierOf(row: $account);
		$code  = $codes->mint(random: $this->random);
		$hash  = $this->secrets->codeHash(code: $code);
		if ($uuid === null || $code === '' || $hash === '') {
			return null;
		}

		$expiresAt = ($now ?? new DateTimeImmutable())->add(new DateInterval(self::TTL))->format(DATE_ATOM);
		// One live secret at a time: a new code ends an earlier link.
		$written = $this->write(id: $uuid, data: ['claimCodeHash' => $hash, 'claimTokenHash' => '', 'claimExpiresAt' => $expiresAt]);
		if ($written === false) {
			return null;
		}

		return ['code' => $codes->shown(code: $code), 'expiresAt' => $expiresAt];
	}//end issueCode()

	/**
	 * Redeem a secret for the person who is signed in.
	 *
	 * The caller's own account must be active and signed in through an
	 * identity provider. The secret must belong to a waiting account in the
	 * session's organisation that is still pending, has no identity reference
	 * and has not expired. The secret is spent before the join, so it can
	 * never work twice, and a wrong one counts against the caller.
	 *
	 * Two exclusive locks make this one step (security review M2, L1): the
	 * caller's account while the attempts are read and counted, and the
	 * waiting account while it is read again, spent and joined. A second
	 * request with the same secret waits, reads the spent hash and joins
	 * nothing.
	 *
	 * @param array<string, mixed> $subject The session, as PortalSessionService resolves it.
	 * @param string $secret The secret the person hands back.
	 * @param DateTimeImmutable|null $now The moment to judge expiry and the lock against.
	 *
	 * @return string One of CLAIMED, NOT_VALID, LOCKED, CANNOT_RECEIVE, CONFLICT and BUSY.
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function redeem(array $subject, #[\SensitiveParameter] string $secret, ?DateTimeImmutable $now = null): string {
		$moment  = ($now ?? new DateTimeImmutable());
		$lookup  = new PortalAccountLookup(reader: $this->reader);
		$account = $this->receiver(subject: $subject, lookup: $lookup);
		if ($account === null) {
			return self::CANNOT_RECEIVE;
		}

		$accountId = (string)$lookup->identifierOf(row: $account);
		if ($this->lock->acquire(accountId: $accountId) === false) {
			return self::BUSY;
		}

		try {
			// Read again inside the lock: the attempt count of a request
			// that ran a moment ago is part of this one's decision.
			$account = $this->receiver(subject: $subject, lookup: $lookup);
			if ($account === null) {
				return self::CANNOT_RECEIVE;
			}

			return $this->redeemLocked(account: $account, accountId: $accountId, subject: $subject, secret: trim($secret), moment: $moment, lookup: $lookup);
		} finally {
			$this->lock->release(accountId: $accountId);
		}
	}//end redeem()

	/**
	 * The part of a redeem that runs while the caller's account is locked.
	 *
	 * @param array<string, mixed> $account The caller's account, read inside the lock.
	 * @param string $accountId The caller's account row identifier.
	 * @param array<string, mixed> $subject The session.
	 * @param string $secret The secret, trimmed.
	 * @param DateTimeImmutable $moment The moment.
	 * @param PortalAccountLookup $lookup The account finder.
	 *
	 * @return string One of the redeem answers.
	 */
	private function redeemLocked(
		array $account,
		string $accountId,
		array $subject,
		#[\SensitiveParameter] string $secret,
		DateTimeImmutable $moment,
		PortalAccountLookup $lookup,
	): string {
		$jti = (string)($subject['jti'] ?? '');
		if ($this->attempts->locked(account: $account, jti: $jti, now: $moment) === true) {
			return self::LOCKED;
		}

		$organisation = (string)$account['organisation'];
		$waitingId    = $lookup->identifierOf(row: ($this->secrets->find(secret: $secret, organisation: $organisation, moment: $moment) ?? []));
		if ($waitingId === null) {
			$this->attempts->fail(account: $account, accountId: $accountId, jti: $jti, now: $moment);
			return self::NOT_VALID;
		}

		if ($this->lock->acquire(accountId: $waitingId) === false) {
			return self::BUSY;
		}

		try {
			$result = $this->claimWaiting(
				account: $account,
				waitingId: $waitingId,
				secret: $secret,
				moment: $moment,
				lookup: $lookup,
				trust: (string)($subject['trust'] ?? '')
			);
		} finally {
			$this->lock->release(accountId: $waitingId);
		}

		if ($result !== self::CLAIMED) {
			return $result;
		}

		$this->attempts->clear(account: $account, accountId: $accountId);
		// Who took over which waiting account, in which session, and when.
		$this->auditor->record(
			verb: self::AUDIT_VERB,
			subjectRef: (string)$account['subjectRef'],
			organisation: $organisation,
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: $waitingId,
			jti: $jti
		);

		return self::CLAIMED;
	}//end redeemLocked()

	/**
	 * Read the waiting account again under its lock, refuse what may not
	 * join before anything is spent, then spend the secret and join.
	 *
	 * Spent first. If the join then fails the invitation is dead and the app
	 * invites again: a secret that worked twice would be worse.
	 *
	 * @param array<string, mixed> $account The caller's account.
	 * @param string $waitingId The waiting account the secret opened a moment ago.
	 * @param string $secret The secret.
	 * @param DateTimeImmutable $moment The moment.
	 * @param PortalAccountLookup $lookup The account finder.
	 * @param string $trust The session's trust level.
	 *
	 * @return string CLAIMED, NOT_VALID or CONFLICT.
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	private function claimWaiting(
		array $account,
		string $waitingId,
		#[\SensitiveParameter] string $secret,
		DateTimeImmutable $moment,
		PortalAccountLookup $lookup,
		string $trust,
	): string {
		// Read again inside the lock: a request that held it a moment ago
		// may have spent this secret.
		$waiting = $this->secrets->find(secret: $secret, organisation: (string)$account['organisation'], moment: $moment);
		if ($waiting === null || $lookup->identifierOf(row: $waiting) !== $waitingId) {
			return self::NOT_VALID;
		}

		$join = new WaitingAccountJoin(lookup: $lookup, writer: $this->writer);
		if ($join->claimsConflict(account: $account, waiting: $waiting) === true) {
			return self::CONFLICT;
		}

		// Another audience (a supplier account redeeming a parent's
		// invitation) and everything else the join refuses: nothing spent.
		// The one exception is a person's own unbound account, which takes
		// on the invitation's audience (invitation-joins-an-unbound-account).
		if ($join->isJoinable(account: $account, waiting: $waiting) === false
			&& $join->mayAdoptAudience(account: $account, waiting: $waiting, trust: $trust) === false
		) {
			return self::NOT_VALID;
		}

		if ($this->write(id: $waitingId, data: ['claimTokenHash' => '', 'claimCodeHash' => '']) === false) {
			return self::NOT_VALID;
		}

		// A link was mailed to the invited address, so following it proves the
		// address; a code came on paper and proves only the letter. The
		// address then arrives unverified (security review L2).
		$byLink = $this->secrets->isCode(secret: $secret) === false;
		if ($join->joinWaiting(account: $account, waiting: $waiting, reason: self::VOID_REASON, addressProven: $byLink, sessionTrust: $trust) === null) {
			return self::NOT_VALID;
		}

		return self::CLAIMED;
	}//end claimWaiting()

	/**
	 * The audience the session's own account holds now, or '' when it has
	 * none or is not in the session's organisation. A redeem can move an
	 * unbound account into the invitation's audience; the caller then
	 * reissues the session so its audience follows the account.
	 *
	 * @param array<string, mixed> $subject The session.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function audienceOf(array $subject): string {
		$account = (new PortalAccountLookup(reader: $this->reader))->bySubjectRef(subjectRef: (string)($subject['subjectRef'] ?? ''));
		if ($account === null
			|| (string)($account['organisation'] ?? '') === ''
			|| (string)($account['organisation'] ?? '') !== (string)($subject['organisation'] ?? '')
		) {
			return '';
		}

		return (string)($account['audience'] ?? '');
	}//end audienceOf()

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
		$receives     = ($account['status'] ?? '') === PortalAccountLookup::STATUS_ACTIVE
			&& (string)($account['identityRef'] ?? '') !== ''
			&& $organisation !== ''
			&& $organisation === (string)($subject['organisation'] ?? '');
		if ($receives === false) {
			return null;
		}

		return $account;
	}//end receiver()

	/**
	 * Whether an account is one this app may send an invitation for.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param string $appId The app asking.
	 * @param bool $byMail Whether the invitation is mailed, which needs an address.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) -- one condition of four
	 * depends on it; two methods would differ in that line only.
	 */
	private function isInvitable(array $account, string $appId, bool $byMail = true): bool {
		$waiting = ($account['status'] ?? '') === PortalAccountLookup::STATUS_PENDING
			&& (string)($account['identityRef'] ?? '') === ''
			&& (string)($account['provisionedBy'] ?? '') === $appId;
		if ($byMail === false) {
			return $waiting;
		}

		return $waiting && trim((string)($account['email'] ?? '')) !== '';
	}//end isInvitable()

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
