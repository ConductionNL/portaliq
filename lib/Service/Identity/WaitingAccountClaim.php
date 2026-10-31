<?php

/**
 * Portaliq Waiting Account Claim
 *
 * The step of a redeem that runs while the waiting account is locked: read it
 * again, refuse what may not join before anything is spent, spend the secret,
 * join, and leave the traces of an audience move. Split out of
 * WaitingAccountInvitation, which holds the locks and the attempt limits.
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
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Joins the waiting account a secret opened.
 *
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */
class WaitingAccountClaim {
	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording an account.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriter   $writer  Spends the secret.
	 * @param WaitingAccountSecret $secrets Finds the waiting account a secret opens.
	 * @param AudienceMove         $moves   Decides and records an account taking on an invitation's audience.
	 */
	public function __construct(
		private readonly PortalObjectWriter $writer,
		private readonly WaitingAccountSecret $secrets,
		private readonly AudienceMove $moves,
	) {
	}//end __construct()

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
	 * @param array<string, mixed> $subject The session.
	 *
	 * @return string CLAIMED, NOT_VALID or CONFLICT.
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	public function claim(
		array $account,
		string $waitingId,
		#[\SensitiveParameter] string $secret,
		DateTimeImmutable $moment,
		PortalAccountLookup $lookup,
		array $subject,
	): string {
		// Read again inside the lock: a request that held it a moment ago
		// may have spent this secret.
		$waiting = $this->reread(secret: $secret, account: $account, moment: $moment, lookup: $lookup, waitingId: $waitingId);
		if ($waiting === null) {
			return WaitingAccountInvitation::NOT_VALID;
		}

		$join = new WaitingAccountJoin(lookup: $lookup, writer: $this->writer);
		if ($join->claimsConflict(account: $account, waiting: $waiting) === true) {
			return WaitingAccountInvitation::CONFLICT;
		}

		// Another audience (a supplier account redeeming a parent's
		// invitation) and everything else the join refuses: nothing spent.
		// The one exception is a person's own unbound account redeeming a
		// mailed link, which takes on the invitation's audience
		// (invitation-joins-an-unbound-account).
		$moving = $join->isJoinable(account: $account, waiting: $waiting) === false;
		$rules  = $this->moveRules(moving: $moving, secret: $secret, account: $account, subject: $subject);
		if ($moving === true && $this->mayMove(join: $join, account: $account, waiting: $waiting, rules: $rules) === false) {
			return WaitingAccountInvitation::NOT_VALID;
		}

		if ($this->spend(id: $waitingId) === false) {
			return WaitingAccountInvitation::NOT_VALID;
		}

		// A link was mailed to the invited address, so following it proves the
		// address; a code came on paper and proves only the letter. The
		// address then arrives unverified (security review L2).
		$byLink = $this->secrets->isCode(secret: $secret) === false;
		$joined = $join->joinWaiting(
			account: $account,
			waiting: $waiting,
			reason: WaitingAccountInvitation::VOID_REASON,
			addressProven: $byLink,
			unbound: $rules
		);
		if ($joined === null) {
			return WaitingAccountInvitation::NOT_VALID;
		}

		if ($moving === true) {
			$this->moves->record(
				account: $account,
				accountId: (string)$lookup->identifierOf(row: $account),
				waiting: $waiting,
				jti: (string)($subject['jti'] ?? ''),
				moment: $moment
			);
		}

		return WaitingAccountInvitation::CLAIMED;
	}//end claim()

	/**
	 * The rules an account is held to when it would take on the invitation's
	 * audience, or null when no move may happen at all: there is none to
	 * make, or the secret is a code from a paper letter. A letter can be
	 * read by anyone in the house, so only the mailed link, which reached the
	 * invited address, may move an audience (second review M1).
	 *
	 * @param bool                 $moving  Whether the join needs a move.
	 * @param string               $secret  The secret.
	 * @param array<string, mixed> $account The caller's account.
	 * @param array<string, mixed> $subject The session.
	 *
	 * @return UnboundAccount|null
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	private function moveRules(bool $moving, #[\SensitiveParameter] string $secret, array $account, array $subject): ?UnboundAccount {
		if ($moving === false || $this->secrets->isCode(secret: $secret) === true) {
			return null;
		}

		return $this->moves->rules(organisation: (string)$account['organisation'], trust: ($subject['trust'] ?? ''));
	}//end moveRules()

	/**
	 * The waiting account the secret opens now, or null when it no longer
	 * opens the one it opened a moment ago.
	 *
	 * @param string               $secret    The secret.
	 * @param array<string, mixed> $account   The caller's account.
	 * @param DateTimeImmutable    $moment    The moment.
	 * @param PortalAccountLookup  $lookup    The account finder.
	 * @param string               $waitingId The waiting account opened before the lock.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reread(
		#[\SensitiveParameter] string $secret,
		array $account,
		DateTimeImmutable $moment,
		PortalAccountLookup $lookup,
		string $waitingId,
	): ?array {
		$waiting = $this->secrets->find(secret: $secret, organisation: (string)$account['organisation'], moment: $moment);
		if ($waiting === null || $lookup->identifierOf(row: $waiting) !== $waitingId) {
			return null;
		}

		return $waiting;
	}//end reread()

	/**
	 * Whether the account may take on the waiting account's audience: there
	 * are rules (a mailed link), and the account meets them.
	 *
	 * @param WaitingAccountJoin   $join    The join.
	 * @param array<string, mixed> $account The caller's account.
	 * @param array<string, mixed> $waiting The waiting account.
	 * @param UnboundAccount|null  $rules   The rules, or null when no move may happen.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
	 */
	private function mayMove(WaitingAccountJoin $join, array $account, array $waiting, ?UnboundAccount $rules): bool {
		return $rules !== null && $join->mayAdoptAudience(account: $account, waiting: $waiting, unbound: $rules) === true;
	}//end mayMove()

	/**
	 * Spend the secrets of the waiting account: an empty scope skips the
	 * writer's ownership re-check, as in WaitingAccountInvitation.
	 *
	 * @param string $id The waiting account's row identifier.
	 *
	 * @return bool True when the write landed.
	 */
	private function spend(string $id): bool {
		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: ['claimTokenHash' => '', 'claimCodeHash' => '']
		) !== null;
	}//end spend()
}//end class
