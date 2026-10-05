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
	 * account: an address the broker says it verified, against a pending,
	 * email-only account whose address was verified out of band, in the same
	 * organisation. A claim the signed-in account already holds is kept, and
	 * so is an address it already has. Best-effort: a failed write leaves the
	 * waiting account pending and never blocks the sign-in.
	 *
	 * @param array<string, mixed> $account The account found on its identity reference.
	 * @param string $verifiedEmail The address the broker says it verified, or ''.
	 * @param string $organisation The tenant slug.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-invitation-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 */
	public function join(array $account, string $verifiedEmail, string $organisation): void {
		if ($verifiedEmail === '') {
			return;
		}

		$waiting = $this->lookup->pendingByVerifiedEmail(email: $verifiedEmail, organisation: $organisation);
		if ($waiting === null || ($waiting['subjectRef'] ?? '') === ($account['subjectRef'] ?? '')) {
			return;
		}

		$accountId = $this->lookup->identifierOf(row: $account);
		$waitingId = $this->lookup->identifierOf(row: $waiting);
		if ($accountId === null || $waitingId === null) {
			return;
		}

		$data = $this->joinData(account: $account, waiting: $waiting);
		if ($data !== [] && $this->write(id: $accountId, data: $data) === false) {
			return;
		}

		$this->write(id: $waitingId, data: ['status' => PortalAccountLookup::STATUS_VOID, 'voidReason' => self::VOID_REASON]);
	}//end join()

	/**
	 * What the join writes onto the signed-in account: the claims it lacks,
	 * and the invited address when it has none of its own.
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
