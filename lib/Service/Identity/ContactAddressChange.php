<?php

/**
 * Portaliq Contact Address Change
 *
 * What the self-service calls write when a person changes their address or
 * follows a confirmation link, and what the account page reads back
 * (identity-profile-page). Kept apart from PortalSelfServiceService so that
 * class keeps to its own work: finding the account and writing the row.
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

/**
 * The address fields of a change, a confirmation and a read.
 *
 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/specs/portal-profile/spec.md
 */
class ContactAddressChange {
	/**
	 * Constructor.
	 *
	 * @param ContactAddressBook $book The address rules.
	 * @param ContactAddressValues $values The address values.
	 */
	public function __construct(
		private readonly ContactAddressBook $book = new ContactAddressBook(),
		private readonly ContactAddressValues $values = new ContactAddressValues(),
	) {
	}//end __construct()

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
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function changedFields(array $account, string $email, string $token): ?array {
		$normalised = $this->values->normalise(kind: 'email', value: $email);
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

		return ['contactAddresses' => $added['entries']] + $this->values->pendingFields(email: $normalised, token: $token, mode: 'replace');
	}//end changedFields()

	/**
	 * The fields a followed confirmation link writes.
	 *
	 * The expiry stays as it was: the cleared hash is what makes the link
	 * dead, and an empty string is not a date-time the schema accepts.
	 *
	 * @param array<string, mixed> $account The account awaiting confirmation.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T03
	 */
	public function confirmedFields(array $account): array {
		$email   = (string)($account['pendingEmail'] ?? '');
		$entries = $this->book->confirm(
			entries: $this->book->entries(account: $account),
			email: $email,
			mode: (string)($account['pendingEmailMode'] ?? 'replace')
		);
		$inUse   = $this->book->preferred(entries: $entries, kind: 'email');
		$data    = [
			'contactAddresses' => $entries,
			'email' => $inUse,
			'pendingEmail' => '',
			'pendingEmailTokenHash' => '',
		];
		if ($inUse === $email) {
			$data['verifiedEmail'] = true;
		}

		return $data;
	}//end confirmedFields()

	/**
	 * What the account page reads about the addresses and the channel.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return array{contactAddresses: array<int, array<string, mixed>>, pendingEmail: string, contactChannel: string}
	 *
	 * @spec openspec/changes/archive/2026-09-30-identity-profile-page/tasks.md#T05
	 */
	public function readFields(array $account): array {
		return [
			'contactAddresses' => $this->book->entries(account: $account),
			'pendingEmail' => $this->values->mask(email: (string)($account['pendingEmail'] ?? '')),
			'contactChannel' => (string)($account['contactChannel'] ?? 'portal'),
		];
	}//end readFields()
}//end class
