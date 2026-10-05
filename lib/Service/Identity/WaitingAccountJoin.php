<?php

/**
 * Portaliq Waiting Account Join
 *
 * A person who signed in before an app provisioned a waiting account for
 * their verified address (a school inviting a guardian who already used
 * DigiD) would never reach that account: the sign-in finds their own account
 * on its identity reference and stops there. This gives the signed-in account
 * the waiting account's claims and withdraws the waiting one.
 *
 * Split out of PortalAccountService, which builds it the way it builds its
 * lookup, so the service's constructor is unchanged.
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
 * @spec openspec/changes/portal-invitation-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * Joins a waiting account into the account that signed in.
 *
 * @spec openspec/changes/portal-invitation-joins-the-signed-in-account/specs/portal-identity-space/spec.md
 */
class WaitingAccountJoin {
	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording an account.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * The reason written on the withdrawn waiting account.
	 */
	public const VOID_REASON = 'Joined the account that signed in with this verified address';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountLookup $lookup Finds the waiting account.
	 * @param PortalObjectWriter $writer Writes both accounts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalAccountLookup $lookup,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * Give a signed-in account the claims of the waiting account for the same
	 * verified address, and withdraw the waiting one.
	 *
	 * The match is the one REQ-PIS-002 already trusts for activating a waiting
	 * account: an address the person is known to hold (the broker says it
	 * verified it, or the person followed the confirmation mail sent to it),
	 * against a pending, email-only account whose address was verified out of
	 * band, in the same organisation and audience. A waiting account that
	 * carries a claim the signed-in account holds with another value is left
	 * alone; an address the account already has is kept. Best-effort: a
	 * failed write leaves the waiting account pending and never blocks the
	 * caller.
	 *
	 * @param array<string, mixed> $account The account found on its identity reference.
	 * @param string $verifiedEmail The verified address, or ''.
	 * @param string $organisation The tenant slug.
	 *
	 * @return string|null The identifier of the waiting account that was
	 *                     joined, or null when nothing was.
	 *
	 * @spec openspec/changes/portal-invitation-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
	 */
	public function join(array $account, string $verifiedEmail, string $organisation): ?string {
		if ($verifiedEmail === '') {
			return null;
		}

		$waiting = $this->lookup->pendingByVerifiedEmail(email: $verifiedEmail, organisation: $organisation);
		if ($waiting === null) {
			return null;
		}

		return $this->joinWaiting(account: $account, waiting: $waiting);
	}//end join()

	/**
	 * Give an account the claims of a waiting account the caller has already
	 * located, and withdraw the waiting one.
	 *
	 * The caller answers for how the waiting account was found. This method
	 * still refuses everything that is not a waiting account: one that is not
	 * pending, one with an identity reference of its own, one in another
	 * organisation or audience, one whose claims conflict with the
	 * receiver's, and the account itself.
	 *
	 * @param array<string, mixed> $account The account that receives the claims.
	 * @param array<string, mixed> $waiting The waiting account.
	 *
	 * @return string|null The identifier of the waiting account that was
	 *                     joined, or null when nothing was.
	 *
	 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
	 */
	public function joinWaiting(array $account, array $waiting): ?string {
		if ($this->isJoinable(account: $account, waiting: $waiting) === false) {
			return null;
		}

		$accountId = $this->lookup->identifierOf(row: $account);
		$waitingId = $this->lookup->identifierOf(row: $waiting);
		if ($accountId === null || $waitingId === null) {
			return null;
		}

		$data = $this->joinData(account: $account, waiting: $waiting);
		if ($data !== [] && $this->write(id: $accountId, data: $data) === false) {
			return null;
		}

		if ($this->write(id: $waitingId, data: ['status' => PortalAccountLookup::STATUS_VOID, 'voidReason' => self::VOID_REASON]) === false) {
			return null;
		}

		return $waitingId;
	}//end joinWaiting()

	/**
	 * Whether one account may take over the claims of another: the other is
	 * pending, has no identity reference of its own, is a different account,
	 * belongs to the same organisation and the same audience, and carries no
	 * claim the receiver holds with a different value.
	 *
	 * The audience: a supplier or business account in the same organisation
	 * never takes over a parent's invitation (security review M3). The
	 * conflict: an account that already holds `learniq.guardianRef = G1`
	 * never silently drops an invitation for `G2` while voiding it, so the
	 * real holder of `G2` can still take it up (security review M1).
	 *
	 * @param array<string, mixed> $account The account that would receive the claims.
	 * @param array<string, mixed> $waiting The account that would be withdrawn.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
	 */
	public function isJoinable(array $account, array $waiting): bool {
		$organisation = (string)($waiting['organisation'] ?? '');
		$audience     = (string)($waiting['audience'] ?? '');

		return ($waiting['status'] ?? '') === PortalAccountLookup::STATUS_PENDING
			&& (string)($waiting['identityRef'] ?? '') === ''
			&& (string)($waiting['subjectRef'] ?? '') !== (string)($account['subjectRef'] ?? '')
			&& $organisation !== ''
			&& $organisation === (string)($account['organisation'] ?? '')
			&& $audience !== ''
			&& $audience === (string)($account['audience'] ?? '')
			&& $this->claimsConflict(account: $account, waiting: $waiting) === false;
	}//end isJoinable()

	/**
	 * Whether the waiting account carries a claim the receiver already holds
	 * under the same app and name with a different value.
	 *
	 * @param array<string, mixed> $account The account that would receive the claims.
	 * @param array<string, mixed> $waiting The account that would be withdrawn.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
	 */
	public function claimsConflict(array $account, array $waiting): bool {
		$held = (array)($account['claims'] ?? []);
		foreach ((array)($waiting['claims'] ?? []) as $appId => $appClaims) {
			if (is_array($appClaims) === false || is_array($held[$appId] ?? null) === false) {
				continue;
			}

			foreach ($appClaims as $name => $value) {
				if (array_key_exists($name, $held[$appId]) === true && $held[$appId][$name] !== $value) {
					return true;
				}
			}
		}

		return false;
	}//end claimsConflict()

	/**
	 * What the join writes onto the signed-in account: the claims it lacks
	 * (a conflicting one never gets this far), and the invited address when
	 * it has none of its own.
	 *
	 * 🔑 THE ADDRESS IS WHY THE WAITING ACCOUNT EXISTS. Carrying only the
	 * claims withdrew the row the address lived on and left the person with
	 * none, so the portal asked a guardian who HAD been invited by e-mail to
	 * add an e-mail address, on every page, and notifications had nowhere to
	 * go. It is written only when the account holds no address, so a person
	 * who has since set their own keeps it, and `verifiedEmail` goes with it:
	 * the address matched because both sides had verified it.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param array<string, mixed> $waiting The waiting account.
	 *
	 * @return array<string, mixed> The fields to write, or [] when none.
	 */
	private function joinData(array $account, array $waiting): array {
		$data   = [];
		$held   = (array)($account['claims'] ?? []);
		$joined = self::claimsJoined(held: $held, added: (array)($waiting['claims'] ?? []));
		if ($joined !== $held) {
			$data['claims'] = $joined;
		}

		$invited = trim((string)($waiting['email'] ?? ''));
		if ($invited !== '' && trim((string)($account['email'] ?? '')) === '') {
			$data['email'] = $invited;
			$data['verifiedEmail'] = true;
		}

		return $data;
	}//end joinData()

	/**
	 * One internal, privileged update of a row this class itself located: an
	 * empty scope skips the writer's ownership re-check, as in
	 * PortalAccountService's own updates.
	 *
	 * @param string $id The row's identifier.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return bool True when the write landed.
	 */
	private function write(string $id, array $data): bool {
		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: $data
		);

		return $written !== null;
	}//end write()

	/**
	 * The claims an account holds, with the claims it lacks added.
	 *
	 * @param array<mixed> $held The claims the account holds, by app id.
	 * @param array<mixed> $added The claims to add, by app id.
	 *
	 * @return array<mixed>
	 */
	private static function claimsJoined(array $held, array $added): array {
		foreach ($added as $appId => $appClaims) {
			if (is_array($appClaims) === false) {
				continue;
			}

			$held[$appId] = ((array)($held[$appId] ?? []) + $appClaims);
		}

		return $held;
	}//end claimsJoined()
}//end class
