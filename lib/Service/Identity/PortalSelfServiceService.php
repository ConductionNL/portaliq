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

use DateTimeImmutable;
use OCA\Portaliq\Service\Notifications\NotificationChannels;
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
	 * A BCP-47 shaped language tag (translated-message-notice): a 2 or 3
	 * letter primary subtag, then optional 1 to 8 character subtags.
	 */
	private const LANGUAGE_TAG = '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Finds the account by subjectRef.
	 * @param PortalObjectReader $reader Looks a confirmation up by hash.
	 * @param PortalObjectWriter $writer Writes the account row.
	 * @param ISecureRandom $random Mints the confirmation secret.
	 * @param ContactAddressBook $book The address rules (identity-profile-page).
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
		private readonly ContactAddressBook $book = new ContactAddressBook(),
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
	 * @param string|null $messageLanguage The language school messages are
	 *                                     shown in, '' to show them as
	 *                                     written, or null to leave it
	 *                                     (translated-message-notice).
	 *
	 * @return array{updated: bool, confirmationToken: string}|null Null when
	 *         there is no such account or nothing usable was asked.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/changes/notification-preferences-per-role/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function updateDetails(
		string $subjectRef,
		string $displayName = '',
		string $email = '',
		?bool $emailNotifications = null,
		?string $messageLanguage = null,
	): ?array {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		$asked   = ['displayName' => $displayName, 'email' => $email, 'emailNotifications' => $emailNotifications, 'messageLanguage' => $messageLanguage];
		if ($account === null || $this->nothingAsked(asked: $asked) === true) {
			return null;
		}

		$data = $this->preferenceData(account: $account, emailNotifications: $emailNotifications, messageLanguage: $messageLanguage);
		if ($data === null) {
			return null;
		}

		if ($displayName !== '') {
			$data['displayName'] = $displayName;
		}

		$token = '';
		if ($email !== '') {
			$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
			$pending = $this->changedAddress(account: $account, email: $email, token: $token);
			if ($pending === null) {
				return null;
			}

			$data = $data + $pending;
		}

		$written = $this->write(account: $account, data: $data);
		if ($written === false) {
			return null;
		}

		return ['updated' => true, 'confirmationToken' => $token];
	}//end updateDetails()

	/**
	 * The holder's own details, as the details page shows them.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return array{displayName: string, email: string, emailNotifications: bool, messageLanguage: string}|null
	 *         Null when there is no such account.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function details(string $subjectRef): ?array {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return null;
		}

		$channels = (array)($account['notificationChannels'] ?? []);
		return [
			'displayName'          => (string)($account['displayName'] ?? ''),
			'email'                => (string)($account['email'] ?? ''),
			'emailNotifications'   => (($channels['email'] ?? true) !== false),
			'messageLanguage'      => (string)($account['messageLanguage'] ?? ''),
			'contactAddresses'     => $this->book->entries(account: $account),
			'pendingEmail'         => $this->book->mask(email: (string)($account['pendingEmail'] ?? '')),
			'contactChannel'       => (string)($account['contactChannel'] ?? 'portal'),
			'notificationChannels' => $channels,
		];
	}//end details()

	/**
	 * The fields that park a changed address until its link is followed, or
	 * null when it is not an address or too many already wait.
	 *
	 * The address joins the list unconfirmed; on confirmation it takes over as
	 * the preferred address (mode `replace`). One already confirmed on the
	 * list takes over at once, without a mail.
	 *
	 * @param array<string, mixed> $account The account as it stands.
	 * @param string $email The new address.
	 * @param string $token The secret for the mail.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/identity-profile-page/tasks.md#T03
	 */
	private function changedAddress(array $account, string $email, string $token): ?array {
		$normalised = $this->book->normalise(kind: 'email', value: $email);
		if ($normalised === null) {
			return null;
		}

		$entries = $this->book->entries(account: $account);
		$added   = $this->book->add(entries: $entries, kind: 'email', value: $normalised);
		if ($added['refusal'] === 'exists') {
			$preferred = $this->book->prefer(entries: $entries, kind: 'email', value: $normalised);
			return ['contactAddresses' => $preferred['entries'], 'email' => $this->book->preferred(entries: $preferred['entries'], kind: 'email')];
		}

		if ($added['refusal'] !== '') {
			return null;
		}

		return ['contactAddresses' => $added['entries']] + $this->book->pendingFields(email: $normalised, token: $token, mode: 'replace');
	}//end changedAddress()

	/**
	 * The language a holder reads school messages in, '' when they read them
	 * as written (translated-message-notice).
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return string The language tag, or ''.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function messageLanguage(string $subjectRef): string {
		return ($this->details(subjectRef: $subjectRef)['messageLanguage'] ?? '');
	}//end messageLanguage()

	/**
	 * The bearer's own notification choices, with whether they registered a
	 * device for push. A missing choice reads as on.
	 *
	 * @param string $subjectRef The bearer's own subject reference.
	 *
	 * @return array{preferences: array<string, array<string, bool>>, pushAvailable: bool}|null Null without an account.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function notificationPreferences(string $subjectRef): ?array {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return null;
		}

		return [
			'preferences' => $this->channels()->preferences(stored: ($account['notificationPreferences'] ?? null)),
			'pushAvailable' => $this->channels()->hasDevice(subjectRef: $subjectRef),
		];
	}//end notificationPreferences()

	/**
	 * Change the bearer's own notification choices. Only the known kinds and
	 * channels with a boolean value are taken; the rest of the body is ignored,
	 * and the account written is always the bearer's own.
	 *
	 * @param string               $subjectRef The bearer's own subject reference.
	 * @param array<string, mixed> $asked      The choices sent.
	 *
	 * @return array{preferences: array<string, array<string, bool>>, pushAvailable: bool}|null Null when refused.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function updateNotificationPreferences(string $subjectRef, array $asked): ?array {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return null;
		}

		$preferences = $this->channels()->merged(stored: ($account['notificationPreferences'] ?? null), asked: $asked);
		if ($this->write(account: $account, data: ['notificationPreferences' => $preferences]) === false) {
			return null;
		}

		// Answer with what was written, not a re-read: a read straight after
		// a write can still see the old row.
		return ['preferences' => $preferences, 'pushAvailable' => $this->channels()->hasDevice(subjectRef: $subjectRef)];
	}//end updateNotificationPreferences()

	/**
	 * The notice choices and device check, over this service's reader.
	 *
	 * @return NotificationChannels
	 */
	private function channels(): NotificationChannels {
		return new NotificationChannels(reader: $this->reader);
	}//end channels()

	/**
	 * Whether an `updateDetails()` call asked for nothing at all.
	 *
	 * @param array{displayName: string, email: string, emailNotifications: bool|null, messageLanguage: string|null} $asked What the call carried.
	 *
	 * @return bool
	 */
	private function nothingAsked(array $asked): bool {
		return $asked['displayName'] === '' && $asked['email'] === ''
			&& $asked['emailNotifications'] === null && $asked['messageLanguage'] === null;
	}//end nothingAsked()

	/**
	 * The delivery preferences an `updateDetails()` call changes, or null when
	 * the language it asked for is not a language tag.
	 *
	 * @param array<string, mixed> $account The account as it stands.
	 * @param bool|null $emailNotifications The email channel, or null to leave it.
	 * @param string|null $messageLanguage The message language, '' for none, or null to leave it.
	 *
	 * @return array<string, mixed>|null The fields to write, or null to refuse.
	 *
	 * @spec openspec/changes/translated-message-notice/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	private function preferenceData(array $account, ?bool $emailNotifications, ?string $messageLanguage): ?array {
		$data = [];
		if ($emailNotifications !== null) {
			$data['notificationChannels'] = $this->withEmailChannel(account: $account, emailNotifications: $emailNotifications);
		}

		if ($messageLanguage === null) {
			return $data;
		}

		if ($messageLanguage !== '' && preg_match(self::LANGUAGE_TAG, $messageLanguage) !== 1) {
			return null;
		}

		$data['messageLanguage'] = $messageLanguage;
		return $data;
	}//end preferenceData()

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

		$email   = (string)($account['pendingEmail'] ?? '');
		$entries = $this->book->confirm(
			entries: $this->book->entries(account: $account),
			email: $email,
			mode: (string)($account['pendingEmailMode'] ?? 'replace')
		);
		$inUse   = $this->book->preferred(entries: $entries, kind: 'email');
		// The expiry stays as it was: the hash is what makes the link dead,
		// and an empty string is not a date-time the schema accepts.
		$data = [
			'contactAddresses' => $entries,
			'email' => $inUse,
			'pendingEmail' => '',
			'pendingEmailTokenHash' => '',
		];
		if ($inUse === $email) {
			$data['verifiedEmail'] = true;
		}

		$written = $this->write(account: $account, data: $data);
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
				'contactAddresses' => [],
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
