<?php

/**
 * Portaliq Confirmed Address Join
 *
 * A person whose sign-in carried no e-mail address (the integriq broker's
 * DigiD answer holds none) never reaches the waiting account an app
 * provisioned for their address. Once they confirm that address through the
 * mailed link, they have proven they hold it, and the waiting account's
 * claims join the account that confirmed it.
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
 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Service\AuditTrailService;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalSessionService;

/**
 * Joins the waiting account for an address its holder just confirmed.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) -- PortalSessionService::trustSatisfies
 * is the one trust comparison every portal surface uses.
 *
 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
 */
class ConfirmedAddressJoin {
	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording an account.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * The audit verb of an account taking over a waiting account's claims.
	 */
	public const AUDIT_VERB = 'claim';

	/**
	 * The trust the confirming session needs before anything is joined: the
	 * floor an invitation's redeem route asks as well.
	 */
	public const MIN_TRUST = 'substantial';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Finds the waiting account.
	 * @param PortalObjectWriter $writer Writes both accounts.
	 * @param AuditTrailService|null $auditor Records the join.
	 * @param ClaimLock|null $lock Keeps a redeem of the same waiting account out while it joins.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ?AuditTrailService $auditor = null,
		private readonly ?ClaimLock $lock = null,
	) {
	}//end __construct()

	/**
	 * Join the waiting account for an address its holder just confirmed.
	 *
	 * Only an active account that signed in through an identity provider
	 * receives claims this way: an account that is itself waiting, removed or
	 * address-only is left alone. The address must be one the account lists
	 * as confirmed. Best-effort: the confirmation stands whether or not
	 * anything was joined.
	 *
	 * THE LINK PROVES THE MAILBOX, NOT WHO ASKED FOR IT. The confirmation
	 * token belongs to the account that added the address; anybody can add
	 * anybody's address. Joining on the link alone gave an attacker the
	 * victim's children the moment the victim opened the mail (security
	 * review H1). So the join runs only when the confirmation arrives in the
	 * confirming account's own session, at trust substantial or higher, in
	 * its own organisation. In every other case the address is confirmed and
	 * nothing is joined.
	 *
	 * @param array<string, mixed> $account The account as it stands after the confirmation.
	 * @param string $email The address that was confirmed.
	 * @param array<string, mixed>|null $session The session the confirmation arrived in, or null.
	 *
	 * @return bool True when a waiting account was joined.
	 *
	 * @spec openspec/changes/confirmed-address-joins-the-waiting-account/specs/portal-identity-space/spec.md
	 */
	public function join(array $account, string $email, ?array $session = null): bool {
		$organisation = (string)($account['organisation'] ?? '');
		if ($this->isHoldersOwnSession(account: $account, session: $session) === false
			|| ($account['status'] ?? '') !== PortalAccountService::STATUS_ACTIVE
			|| (string)($account['identityRef'] ?? '') === ''
			|| $this->holdsConfirmed(account: $account, email: $email) === false
		) {
			return false;
		}

		$joined = $this->joinLocked(account: $account, email: $email, organisation: $organisation);
		if ($joined === null) {
			return false;
		}

		// Who took over which waiting account, and when: the row's user is
		// the account that confirmed, its target the account withdrawn.
		$this->auditor?->record(
			verb: self::AUDIT_VERB,
			subjectRef: (string)($account['subjectRef'] ?? ''),
			organisation: $organisation,
			register: self::REGISTER,
			schema: self::SCHEMA,
			id: $joined
		);

		return true;
	}//end join()

	/**
	 * Find the waiting account for the address, lock it, read it again and
	 * join it, so an invitation redeemed at the same moment cannot join it a
	 * second time (security review M2).
	 *
	 * @param array<string, mixed> $account The confirming account.
	 * @param string $email The confirmed address.
	 * @param string $organisation The tenant slug.
	 *
	 * @return string|null The identifier of the waiting account joined, or null.
	 */
	private function joinLocked(array $account, string $email, string $organisation): ?string {
		$lookup    = new PortalAccountLookup(reader: $this->reader);
		$waitingId = $lookup->identifierOf(row: ($lookup->pendingByVerifiedEmail(email: $email, organisation: $organisation) ?? []));
		if ($waitingId === null || $this->lock?->acquire(accountId: $waitingId) === false) {
			return null;
		}

		try {
			$waiting = $lookup->pendingByVerifiedEmail(email: $email, organisation: $organisation);
			if ($waiting === null || $lookup->identifierOf(row: $waiting) !== $waitingId) {
				return null;
			}

			return (new WaitingAccountJoin(lookup: $lookup, writer: $this->writer))->joinWaiting(account: $account, waiting: $waiting);
		} finally {
			$this->lock?->release(accountId: $waitingId);
		}
	}//end joinLocked()

	/**
	 * Whether the confirmation arrived in the account holder's own session:
	 * the session's subject is the account's, in the account's organisation,
	 * at trust substantial or higher.
	 *
	 * @param array<string, mixed> $account The confirming account.
	 * @param array<string, mixed>|null $session The session, or null.
	 *
	 * @return bool
	 */
	private function isHoldersOwnSession(array $account, ?array $session): bool {
		if ($session === null) {
			return false;
		}

		$subjectRef   = (string)($account['subjectRef'] ?? '');
		$organisation = (string)($account['organisation'] ?? '');

		return $subjectRef !== ''
			&& (string)($session['subjectRef'] ?? '') === $subjectRef
			&& $organisation !== ''
			&& (string)($session['organisation'] ?? '') === $organisation
			&& PortalSessionService::trustSatisfies(subjectTrust: ($session['trust'] ?? ''), minTrust: self::MIN_TRUST) === true;
	}//end isHoldersOwnSession()

	/**
	 * Whether the account lists an e-mail address as confirmed, whatever the
	 * case it was typed in (security review L4).
	 *
	 * @param array<string, mixed> $account The account.
	 * @param string $email The address.
	 *
	 * @return bool
	 */
	private function holdsConfirmed(array $account, string $email): bool {
		if ($email === '') {
			return false;
		}

		foreach ((array)($account['contactAddresses'] ?? []) as $entry) {
			if (is_array($entry) === true
				&& ($entry['kind'] ?? '') === 'email'
				&& strtolower((string)($entry['value'] ?? '')) === strtolower($email)
				&& ($entry['confirmed'] ?? false) === true
			) {
				return true;
			}
		}

		return false;
	}//end holdsConfirmed()
}//end class
