<?php

/**
 * Portaliq Contact Address Book
 *
 * The rules for the e-mail addresses and phone numbers a person keeps on their
 * own portal account (identity-profile-page, design D2). Pure: it takes the
 * account's list and answers the new list, so every rule is testable without a
 * store.
 *
 * - An e-mail address is added unconfirmed. It is used for nothing until the
 *   link in its confirmation mail is followed.
 * - At most one preferred address per kind. Only a confirmed e-mail address can
 *   be preferred; the preferred one is the account's `email`, the only address
 *   notification dispatch reads.
 * - The preferred e-mail cannot be removed while another confirmed one exists:
 *   the person picks the next one first.
 * - At most five unconfirmed e-mail addresses per account, so one session
 *   cannot turn the portal into a mail cannon.
 *
 * The values themselves (a phone number's E.164 form, a masked address, the
 * pending-confirmation fields) are ContactAddressValues'.
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
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-keep-several-addresses-one-of-each-kind-preferred-req-ipp-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

/**
 * The address rules of one account.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md#requirement-you-keep-several-addresses-one-of-each-kind-preferred-req-ipp-003
 */
class ContactAddressBook {
	/**
	 * The two kinds of address.
	 */
	public const KINDS = ['email', 'phone'];

	/**
	 * Unconfirmed e-mail addresses an account may hold at once.
	 */
	public const MAX_PENDING = 5;

	/**
	 * The account's addresses, each `{kind, value, confirmed, preferred}`.
	 *
	 * An account from before this change has only `email`: it is read as one
	 * confirmed, preferred address, because it is the address in use.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return array<int, array{kind: string, value: string, confirmed: bool, preferred: bool}>
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function entries(array $account): array {
		$entries = [];
		foreach ((array)($account['contactAddresses'] ?? []) as $entry) {
			if (is_array($entry) === false
				|| in_array(($entry['kind'] ?? ''), self::KINDS, true) === false
				|| (string)($entry['value'] ?? '') === ''
			) {
				continue;
			}

			$entries[] = [
				'kind' => (string)$entry['kind'],
				'value' => (string)$entry['value'],
				'confirmed' => (($entry['confirmed'] ?? false) === true),
				'preferred' => (($entry['preferred'] ?? false) === true),
			];
		}

		$email = (string)($account['email'] ?? '');
		if ($email !== '' && $this->find(entries: $entries, kind: 'email', value: $email) === null) {
			$entries = $this->withPreferred(
				entries: array_merge($entries, [['kind' => 'email', 'value' => $email, 'confirmed' => true, 'preferred' => false]]),
				kind: 'email',
				value: $email
			);
		}

		return $entries;
	}//end entries()

	/**
	 * Add an address.
	 *
	 * An e-mail address already on the list and still unconfirmed is not
	 * added twice: the answer asks for a fresh confirmation mail instead.
	 *
	 * @param array<int, array<string, mixed>> $entries The list as it stands.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address, already normalised.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, refusal: string, confirm: bool}
	 *         `refusal` is '' on success, else `exists` or `too_many_pending`;
	 *         `confirm` says a confirmation mail is due.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function add(array $entries, string $kind, string $value): array {
		$found = $this->find(entries: $entries, kind: $kind, value: $value);
		if ($found !== null) {
			if ($kind === 'email' && $found['confirmed'] === false) {
				// Asked again for an address that waits: a fresh mail, no second entry.
				return ['entries' => $entries, 'refusal' => '', 'confirm' => true];
			}

			return ['entries' => $entries, 'refusal' => 'exists', 'confirm' => false];
		}

		if ($kind === 'email' && $this->pendingCount(entries: $entries) >= self::MAX_PENDING) {
			return ['entries' => $entries, 'refusal' => 'too_many_pending', 'confirm' => false];
		}

		// A first phone number is the preferred one; a phone is never
		// confirmed, because there is no SMS channel to confirm it with.
		$first = ($kind === 'phone' && $this->preferred(entries: $entries, kind: 'phone') === '');
		$entries[] = ['kind' => $kind, 'value' => $value, 'confirmed' => false, 'preferred' => $first];

		return ['entries' => $entries, 'refusal' => '', 'confirm' => ($kind === 'email')];
	}//end add()

	/**
	 * Mark one address as the preferred one of its kind.
	 *
	 * @param array<int, array<string, mixed>> $entries The list as it stands.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, refusal: string}
	 *         `refusal` is '' on success, else `not_found` or `confirm_first`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function prefer(array $entries, string $kind, string $value): array {
		$found = $this->find(entries: $entries, kind: $kind, value: $value);
		if ($found === null) {
			return ['entries' => $entries, 'refusal' => 'not_found'];
		}

		if ($kind === 'email' && $found['confirmed'] === false) {
			return ['entries' => $entries, 'refusal' => 'confirm_first'];
		}

		return ['entries' => $this->withPreferred(entries: $entries, kind: $kind, value: $value), 'refusal' => ''];
	}//end prefer()

	/**
	 * Remove an address.
	 *
	 * @param array<int, array<string, mixed>> $entries The list as it stands.
	 * @param string $kind `email` or `phone`.
	 * @param string $value The address.
	 *
	 * @return array{entries: array<int, array<string, mixed>>, refusal: string}
	 *         `refusal` is '' on success, else `not_found` or
	 *         `choose_another_preferred`.
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function remove(array $entries, string $kind, string $value): array {
		$found = $this->find(entries: $entries, kind: $kind, value: $value);
		if ($found === null) {
			return ['entries' => $entries, 'refusal' => 'not_found'];
		}

		if ($kind === 'email' && $found['preferred'] === true && $this->confirmedOthers(entries: $entries, value: $value) > 0) {
			return ['entries' => $entries, 'refusal' => 'choose_another_preferred'];
		}

		$left = array_values(
			array_filter(
				$entries,
				static fn (array $entry): bool => ($entry['kind'] !== $kind || $entry['value'] !== $value)
			)
		);

		return ['entries' => $left, 'refusal' => ''];
	}//end remove()

	/**
	 * The list after an e-mail address was confirmed through its link.
	 *
	 * It becomes the preferred address when the person asked to change their
	 * address (`$mode` `replace`), or when the account has no preferred
	 * address yet; otherwise it stays one of the others.
	 *
	 * @param array<int, array<string, mixed>> $entries The list as it stands.
	 * @param string $email The confirmed address.
	 * @param string $mode `replace` or `add`.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function confirm(array $entries, string $email, string $mode): array {
		if ($this->find(entries: $entries, kind: 'email', value: $email) === null) {
			$entries[] = ['kind' => 'email', 'value' => $email, 'confirmed' => false, 'preferred' => false];
		}

		foreach ($entries as $index => $entry) {
			if ($entry['kind'] === 'email' && $entry['value'] === $email) {
				$entries[$index]['confirmed'] = true;
			}
		}

		if ($mode === 'replace' || $this->preferred(entries: $entries, kind: 'email') === '') {
			return $this->withPreferred(entries: $entries, kind: 'email', value: $email);
		}

		return $entries;
	}//end confirm()

	/**
	 * The preferred address of a kind, or ''.
	 *
	 * @param array<int, array<string, mixed>> $entries The list.
	 * @param string $kind `email` or `phone`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function preferred(array $entries, string $kind): string {
		foreach ($entries as $entry) {
			if ($entry['kind'] === $kind && $entry['preferred'] === true) {
				return (string)$entry['value'];
			}
		}

		return '';
	}//end preferred()

	/**
	 * One address on the list, or null.
	 *
	 * @param array<int, array<string, mixed>> $entries The list.
	 * @param string $kind The kind.
	 * @param string $value The address.
	 *
	 * @return array<string, mixed>|null
	 */
	private function find(array $entries, string $kind, string $value): ?array {
		foreach ($entries as $entry) {
			if ($entry['kind'] === $kind && strcasecmp((string)$entry['value'], $value) === 0) {
				return $entry;
			}
		}

		return null;
	}//end find()

	/**
	 * The list with exactly one preferred address of this kind.
	 *
	 * @param array<int, array<string, mixed>> $entries The list.
	 * @param string $kind The kind.
	 * @param string $value The address to prefer.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function withPreferred(array $entries, string $kind, string $value): array {
		foreach ($entries as $index => $entry) {
			if ($entry['kind'] === $kind) {
				$entries[$index]['preferred'] = (strcasecmp((string)$entry['value'], $value) === 0);
			}
		}

		return $entries;
	}//end withPreferred()

	/**
	 * How many e-mail addresses wait for confirmation.
	 *
	 * @param array<int, array<string, mixed>> $entries The list.
	 *
	 * @return int
	 */
	private function pendingCount(array $entries): int {
		return count(array_filter($entries, static fn (array $entry): bool => ($entry['kind'] === 'email' && $entry['confirmed'] === false)));
	}//end pendingCount()

	/**
	 * How many confirmed e-mail addresses other than this one there are.
	 *
	 * @param array<int, array<string, mixed>> $entries The list.
	 * @param string $value The address to leave out.
	 *
	 * @return int
	 */
	private function confirmedOthers(array $entries, string $value): int {
		return count(
			array_filter(
				$entries,
				static fn (array $entry): bool => $entry['kind'] === 'email'
					&& $entry['confirmed'] === true
					&& strcasecmp((string)$entry['value'], $value) !== 0
			)
		);
	}//end confirmedOthers()
}//end class
