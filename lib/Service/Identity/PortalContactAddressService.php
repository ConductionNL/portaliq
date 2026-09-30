<?php

/**
 * Portaliq Portal Contact Address Service
 *
 * The addresses and the contact channel a signed-in person keeps on their own
 * portal account (identity-profile-page, D2 and D3). The rules live in
 * ContactAddressBook; this service reads the account, applies them, writes the
 * row, mints the confirmation secret for a new e-mail address, and announces a
 * changed contact channel.
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
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use OCA\Portaliq\Event\PortalContactDetailsChangedEvent;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Security\ISecureRandom;

/**
 * A person's own addresses and contact channel.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */
class PortalContactAddressService {
	/**
	 * The register the account lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param PortalAccountService $accounts Finds the account by subjectRef.
	 * @param PortalObjectWriter $writer Writes the account row.
	 * @param ISecureRandom $random Mints the confirmation secret.
	 * @param IEventDispatcher $dispatcher Announces a changed contact channel (ADR-041).
	 * @param ContactAddressBook $book The address rules.
	 */
	public function __construct(
		private readonly PortalAccountService $accounts,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
		private readonly IEventDispatcher $dispatcher,
		private readonly ContactAddressBook $book,
	) {
	}//end __construct()

	/**
	 * Add an e-mail address or phone number.
	 *
	 * A new e-mail address is parked with a one-time secret, returned for the
	 * mail; asking again for an address that waits for confirmation mints a
	 * fresh secret, and the earlier link stops working.
	 *
	 * @param string $subjectRef The account.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address as typed.
	 *
	 * @return array{refusal: string, value: string, confirmationToken: string}
	 *         `refusal` '' on success, else `no_account`, `invalid`, `exists`,
	 *         `too_many_pending` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function addAddress(string $subjectRef, string $kind, string $value): array {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return $this->refused(refusal: 'no_account');
		}

		$normalised = $this->book->normalise(kind: $kind, value: $value);
		if ($normalised === null) {
			return $this->refused(refusal: 'invalid');
		}

		$added = $this->book->add(entries: $this->book->entries(account: $account), kind: $kind, value: $normalised);
		if ($added['refusal'] !== '') {
			return $this->refused(refusal: $added['refusal']);
		}

		$data  = ['contactAddresses' => $added['entries']];
		$token = '';
		if ($added['confirm'] === true) {
			$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
			$data  = $data + $this->book->pendingFields(email: $normalised, token: $token, mode: 'add');
		}

		if ($this->write(account: $account, data: $data) === false) {
			return $this->refused(refusal: 'not_written');
		}

		return ['refusal' => '', 'value' => $normalised, 'confirmationToken' => $token];
	}//end addAddress()

	/**
	 * Mark an address as the preferred one of its kind. A preferred e-mail
	 * address becomes the account's `email`, the address notifications go to.
	 *
	 * @param string $subjectRef The account.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return string '' on success, else `no_account`, `not_found`,
	 *                `confirm_first` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function preferAddress(string $subjectRef, string $kind, string $value): string {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return 'no_account';
		}

		$preferred = $this->book->prefer(entries: $this->book->entries(account: $account), kind: $kind, value: trim($value));
		if ($preferred['refusal'] !== '') {
			return $preferred['refusal'];
		}

		return $this->writeEntries(account: $account, entries: $preferred['entries']);
	}//end preferAddress()

	/**
	 * Remove an address.
	 *
	 * @param string $subjectRef The account.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return string '' on success, else `no_account`, `not_found`,
	 *                `choose_another_preferred` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function removeAddress(string $subjectRef, string $kind, string $value): string {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return 'no_account';
		}

		$removed = $this->book->remove(entries: $this->book->entries(account: $account), kind: $kind, value: trim($value));
		if ($removed['refusal'] !== '') {
			return $removed['refusal'];
		}

		// Removing the address that waits for its link also kills the link.
		$extra = [];
		if ($kind === 'email' && strcasecmp((string)($account['pendingEmail'] ?? ''), trim($value)) === 0) {
			$extra = ['pendingEmail' => '', 'pendingEmailTokenHash' => ''];
		}

		return $this->writeEntries(account: $account, entries: $removed['entries'], extra: $extra);
	}//end removeAddress()

	/**
	 * Choose how the organisation contacts the person. A change is announced
	 * once as PortalContactDetailsChangedEvent; saving the same channel again
	 * announces nothing.
	 *
	 * @param string $subjectRef The account.
	 * @param string $channel `portal`, `email`, `phone` or `post`.
	 *
	 * @return string '' on success, else `no_account`, `invalid` or `not_written`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T04
	 */
	public function chooseChannel(string $subjectRef, string $channel): string {
		$account = $this->ownAccount(subjectRef: $subjectRef);
		if ($account === null) {
			return 'no_account';
		}

		if (in_array($channel, ContactAddressBook::CHANNELS, true) === false) {
			return 'invalid';
		}

		if ((string)($account['contactChannel'] ?? 'portal') === $channel) {
			return '';
		}

		if ($this->write(account: $account, data: ['contactChannel' => $channel]) === false) {
			return 'not_written';
		}

		$entries = $this->book->entries(account: $account);
		$this->dispatcher->dispatchTyped(
			new PortalContactDetailsChangedEvent(
				subjectRef: $subjectRef,
				organisation: (string)($account['organisation'] ?? ''),
				channel: $channel,
				preferred: [
					'email' => $this->book->preferred(entries: $entries, kind: 'email') !== '',
					'phone' => $this->book->preferred(entries: $entries, kind: 'phone') !== '',
				]
			)
		);

		return '';
	}//end chooseChannel()

	/**
	 * Write a new address list, and the preferred e-mail into `email`.
	 *
	 * @param array<string, mixed> $account The account row.
	 * @param array<int, array<string, mixed>> $entries The new list.
	 * @param array<string, mixed> $extra Further fields to write with it.
	 *
	 * @return string '' when written, else `not_written`.
	 */
	private function writeEntries(array $account, array $entries, array $extra = []): string {
		$email = $this->book->preferred(entries: $entries, kind: 'email');
		$data  = ['contactAddresses' => $entries, 'email' => $email] + $extra;
		if ($email !== '' && $email !== (string)($account['email'] ?? '')) {
			$data['verifiedEmail'] = true;
		}

		return $this->write(account: $account, data: $data) === true ? '' : 'not_written';
	}//end writeEntries()

	/**
	 * A refusal in the shape addAddress() answers.
	 *
	 * @param string $refusal Why.
	 *
	 * @return array{refusal: string, value: string, confirmationToken: string}
	 */
	private function refused(string $refusal): array {
		return ['refusal' => $refusal, 'value' => '', 'confirmationToken' => ''];
	}//end refused()

	/**
	 * The account behind a subjectRef, while it is not removed.
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
		$self = (array)($account['@self'] ?? []);
		$id   = (string)($account['uuid'] ?? $account['id'] ?? $self['uuid'] ?? $self['id'] ?? '');
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
