<?php

/**
 * Portaliq Portal Self Service Service
 *
 * What a signed-in citizen may do to their own portal account: change their
 * details, and ask for the account to go.
 *
 * A new address is used for nothing until the link in it is followed. That is
 * the whole mechanism: until then the old address keeps receiving everything,
 * so a typo, or somebody else's address, never silently takes over the
 * account's mail.
 *
 * Removal empties the account of the person: identity, address, display name
 * and every claim. The cases stay, because they are the municipality's record
 * of what happened and not the citizen's property to withdraw.
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
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * The citizen's own account: their details, and its removal.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalSelfServiceService {
	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording the account.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * How long a confirmation link works.
	 */
	private const TTL = 'P1D';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Finds the account by subjectRef.
	 * @param PortalObjectReader $reader Looks a confirmation up by hash.
	 * @param PortalObjectWriter $writer Writes the account row.
	 * @param ISecureRandom $random Mints the confirmation secret.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Change the details the citizen may change themselves.
	 *
	 * The display name lands at once. A new address does not: it is parked as
	 * `pendingEmail` with a one-time secret, and the answer carries that
	 * secret for the mail.
	 *
	 * @param string $subjectRef The account.
	 * @param string $displayName A new name, or '' to leave it.
	 * @param string $email A new address, or '' to leave it.
	 * @param bool|null $emailNotifications The account's own opt-in/opt-out
	 *                                      for the email channel
	 *                                      (notification-preferences-per-role),
	 *                                      or null to leave it unchanged.
	 *
	 * @return array{updated: bool, confirmationToken: string}|null Null when
	 *         there is no such account or nothing usable was asked.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch
	 */
	public function updateDetails(string $subjectRef, string $displayName = '', string $email = '', ?bool $emailNotifications = null): ?array {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null || $this->nothingAsked(displayName: $displayName, email: $email, emailNotifications: $emailNotifications) === true) {
			return null;
		}

		$data = [];
		if ($displayName !== '') {
			$data['displayName'] = $displayName;
		}

		if ($emailNotifications !== null) {
			$data['notificationChannels'] = $this->withEmailChannel(account: $account, emailNotifications: $emailNotifications);
		}

		$token = '';
		if ($email !== '') {
			if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
				return null;
			}

			$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
			$data['pendingEmail'] = $email;
			$data['pendingEmailTokenHash'] = hash('sha256', $token);
			$data['pendingEmailExpiresAt'] = (new DateTimeImmutable())->add(new DateInterval(self::TTL))->format(DATE_ATOM);
		}

		$written = $this->write(account: $account, data: $data);
		if ($written === false) {
			return null;
		}

		return ['updated' => true, 'confirmationToken' => $token];
	}//end updateDetails()

	/**
	 * Whether an `updateDetails()` call asked for nothing at all.
	 *
	 * @param string $displayName A new name, or '' to leave it.
	 * @param string $email A new address, or '' to leave it.
	 * @param bool|null $emailNotifications The channel opt-in/opt-out, or
	 *                                      null to leave it.
	 *
	 * @return bool
	 */
	private function nothingAsked(string $displayName, string $email, ?bool $emailNotifications): bool {
		return $displayName === '' && $email === '' && $emailNotifications === null;
	}//end nothingAsked()

	/**
	 * The account's `notificationChannels` with the email key set, its other
	 * channels (if any exist in future) left untouched.
	 *
	 * @param array<string, mixed> $account The account as it stands.
	 * @param bool $emailNotifications The new value for the email channel.
	 *
	 * @return array<string, bool>
	 *
	 * @spec openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch
	 */
	private function withEmailChannel(array $account, bool $emailNotifications): array {
		$channels = (array)($account['notificationChannels'] ?? []);
		$channels['email'] = $emailNotifications;
		return $channels;
	}//end withEmailChannel()

	/**
	 * Confirm a new address through the link.
	 *
	 * @param string $token The secret from the confirmation mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{email: string}|null Null when the link admits nobody:
	 *         unknown, already used or expired.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function confirmEmail(string $token, ?DateTimeImmutable $now = null): ?array {
		if ($token === '') {
			return null;
		}

		$account = $this->accountAwaitingConfirmation(
			hash: hash('sha256', $token),
			moment: ($now ?? new DateTimeImmutable())
		);
		if ($account === null) {
			return null;
		}

		$email = (string)($account['pendingEmail'] ?? '');
		$written = $this->write(
			account: $account,
			data: [
				'email' => $email,
				'verifiedEmail' => true,
				'pendingEmail' => '',
				'pendingEmailTokenHash' => '',
				'pendingEmailExpiresAt' => '',
			]
		);
		if ($written === false) {
			return null;
		}

		return ['email' => $email];
	}//end confirmEmail()

	/**
	 * The account whose pending address this token confirms, or null.
	 *
	 * Unknown, already spent, addressless and expired all answer null: the
	 * caller holds a secret or they do not, and nothing else is theirs to
	 * learn.
	 *
	 * @param string $hash The token's hash.
	 * @param DateTimeImmutable $moment The moment to judge expiry against.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	private function accountAwaitingConfirmation(string $hash, DateTimeImmutable $moment): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'pendingEmailTokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);

		$account = null;
		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['pendingEmailTokenHash'] ?? ''), $hash) === true) {
				$account = $row;
				break;
			}
		}

		if ($account === null || (string)($account['pendingEmail'] ?? '') === '') {
			return null;
		}

		$expiry = date_create_immutable((string)($account['pendingEmailExpiresAt'] ?? ''));
		if ($expiry === false || $expiry <= $moment) {
			return null;
		}

		return $account;
	}//end accountAwaitingConfirmation()

	/**
	 * Carry out the citizen's request to have the account removed.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return bool True when the account is emptied and marked removed.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function removeAccount(string $subjectRef): bool {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return false;
		}

		return $this->write(
			account: $account,
			data: [
				'status' => 'removed',
				'identityType' => '',
				'identityRef' => '',
				'email' => '',
				'pendingEmail' => '',
				'pendingEmailTokenHash' => '',
				'displayName' => '',
				'verifiedEmail' => false,
				// Every app's link to this person goes with the account. The
				// cases themselves are never touched here: they are the
				// municipality's record.
				'claims' => [],
				'removedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
			]
		);
	}//end removeAccount()

	/**
	 * The account behind a subjectRef, when it is one a citizen may still act
	 * on.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return array<string, mixed>|null
	 */
	private function ownAccount(string $subjectRef): ?array {
		if ($subjectRef === '') {
			return null;
		}

		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if ($account === null || ($account['status'] ?? '') === 'removed') {
			return null;
		}

		return $account;
	}//end ownAccount()

	/**
	 * Write on the account row.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param array<string, mixed> $data The fields to write.
	 *
	 * @return bool
	 */
	private function write(array $account, array $data): bool {
		$id = (string)($account['uuid'] ?? $account['id'] ?? '');
		if ($id === '') {
			$self = (array)($account['@self'] ?? []);
			$id = (string)($self['uuid'] ?? $self['id'] ?? '');
		}

		if ($id === '') {
			return false;
		}

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
