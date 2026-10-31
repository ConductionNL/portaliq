<?php

/**
 * Portaliq Contact Invitation Link
 *
 * Following a resident's contact invitation link: accept it signed in, hold
 * it for a registration, join both sides when that account is activated, and
 * name the address it went to.
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;

/**
 * The secret is only ever compared by its hash (ContactRows::byToken); a
 * joined or expired invitation answers like an unknown one.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
 */
class ContactInvitationLink {

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads the rows held for a new account.
	 * @param ContactRows $rows Finds the invitation by its secret.
	 * @param PortalObjectWriter $writer Writes both sides.
	 * @param PortalAccountLookup $accounts Reads the inviter's name.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly ContactRows $rows,
		private readonly PortalObjectWriter $writer,
		private readonly PortalAccountLookup $accounts,
	) {
	}//end __construct()

	/**
	 * A signed-in account follows an invitation link: both sides join.
	 *
	 * @param array<string, mixed> $subject The account following the link.
	 * @param string $token The secret from the mail.
	 * @param string $displayName The account's name, as the inviter sees it.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return string SENT when joined, else NOT_FOUND (also for an expired or used link), or FAILED.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function accept(array $subject, string $token, string $displayName, ?DateTimeImmutable $now=null): string {
		$newRef       = (string)($subject['subjectRef'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		if ($token === '' || $newRef === '' || $organisation === '') {
			return PortalContactService::NOT_FOUND;
		}

		$row = $this->openInvitation(token: $token, newRef: $newRef, organisation: $organisation, now: ($now ?? new DateTimeImmutable()));
		if ($row === null) {
			return PortalContactService::NOT_FOUND;
		}

		return $this->join(row: $row, newRef: $newRef, displayName: $displayName, organisation: $organisation);
	}//end accept()

	/**
	 * A visitor who registers through an invitation link: the open
	 * invitation is held for the new account (its `contactRef`), so the
	 * activation can join both sides even when the activation link opens in
	 * another browser tab, which has no kept secret.
	 *
	 * The row stays `invited` and keeps its secret: a link followed while
	 * signed in still joins the same way.
	 *
	 * @param string $token The secret from the invitation mail.
	 * @param string $newRef The new, not yet active account.
	 * @param string $organisation The tenant.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return bool Whether an open invitation was held.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function holdForRegistration(string $token, string $newRef, string $organisation, ?DateTimeImmutable $now=null): bool {
		if ($token === '' || $newRef === '' || $organisation === '') {
			return false;
		}

		$row = $this->openInvitation(token: $token, newRef: $newRef, organisation: $organisation, now: ($now ?? new DateTimeImmutable()));
		if ($row === null) {
			return false;
		}

		return $this->writer->updateObject(
			register: PortalContactService::REGISTER,
			schema: PortalContactService::SCHEMA,
			scopeField: 'owner',
			subjectRef: (string)$row['owner'],
			organisation: $organisation,
			id: $this->rows->idOf(row: $row),
			data: ['contactRef' => $newRef]
		) !== null;
	}//end holdForRegistration()

	/**
	 * An account was activated: every invitation held for it joins both
	 * sides, without a second approval (REQ-ROC-003).
	 *
	 * @param array<string, mixed> $account The activated account: `subjectRef`, `organisation`, `displayName`.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return int How many invitations joined.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function joinOnActivation(array $account, ?DateTimeImmutable $now=null): int {
		$newRef       = (string)($account['subjectRef'] ?? '');
		$organisation = (string)($account['organisation'] ?? '');
		$now          = ($now ?? new DateTimeImmutable());
		$joined       = 0;
		foreach ($this->heldFor(contactRef: $newRef, organisation: $organisation) as $row) {
			if ((string)($row['owner'] ?? '') === $newRef || $this->rows->expired(row: $row, now: $now) === true) {
				continue;
			}

			$name = (string)($account['displayName'] ?? '');
			if ($this->join(row: $row, newRef: $newRef, displayName: $name, organisation: $organisation) === PortalContactService::SENT) {
				$joined++;
			}
		}

		return $joined;
	}//end joinOnActivation()

	/**
	 * The address an open invitation went to, so registration can show it
	 * filled in. Only the holder of the secret asks, and they got the mail.
	 *
	 * @param string $token The secret from the invitation mail.
	 * @param string $organisation The tenant of the portal being visited.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return string The address, or '' for a link that is unknown, expired or used.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function invitationEmail(string $token, string $organisation, ?DateTimeImmutable $now=null): string {
		if ($token === '' || $organisation === '') {
			return '';
		}

		$row = $this->openInvitation(token: $token, newRef: '', organisation: $organisation, now: ($now ?? new DateTimeImmutable()));

		return (string)($row['email'] ?? '');
	}//end invitationEmail()

	/**
	 * The open invitation a secret belongs to, or null when it is unknown,
	 * no longer `invited`, expired, or the inviter's own.
	 *
	 * @param string $token The secret.
	 * @param string $newRef The account following it ('' when none yet).
	 * @param string $organisation The tenant.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function openInvitation(string $token, string $newRef, string $organisation, DateTimeImmutable $now): ?array {
		$row = $this->rows->byToken(token: $token, organisation: $organisation);
		if ($row === null || (string)($row['state'] ?? '') !== 'invited' || $this->rows->expired(row: $row, now: $now) === true) {
			return null;
		}

		if ($newRef !== '' && (string)($row['owner'] ?? '') === $newRef) {
			return null;
		}

		return $row;
	}//end openInvitation()

	/**
	 * Join both sides of an invitation: the inviter's row becomes approved
	 * with the new account, and the new account gets its own row.
	 *
	 * @param array<string, mixed> $row The inviter's `invited` row.
	 * @param string $newRef The new account.
	 * @param string $displayName The new account's name, as the inviter sees it.
	 * @param string $organisation The tenant.
	 *
	 * @return string SENT, or FAILED when the inviter's row could not be written.
	 */
	private function join(array $row, string $newRef, string $displayName, string $organisation): string {
		$inviter = (string)$row['owner'];
		$written = $this->writer->updateObject(
			register: PortalContactService::REGISTER,
			schema: PortalContactService::SCHEMA,
			scopeField: 'owner',
			subjectRef: $inviter,
			organisation: $organisation,
			id: $this->rows->idOf(row: $row),
			data: ['contactRef' => $newRef, 'displayName' => $displayName, 'state' => 'approved', 'tokenHash' => '', 'email' => '']
		);
		if ($written === null) {
			return PortalContactService::FAILED;
		}

		$inviterAccount = $this->accounts->bySubjectRef(subjectRef: $inviter);
		$this->writer->createObject(
			register: PortalContactService::REGISTER,
			schema: PortalContactService::SCHEMA,
			scopeField: 'owner',
			subjectRef: $newRef,
			organisation: $organisation,
			data: ['contactRef' => $inviter, 'role' => 'contact', 'displayName' => (string)($inviterAccount['displayName'] ?? ''), 'state' => 'approved']
		);

		return PortalContactService::SENT;
	}//end join()

	/**
	 * The open invitations a new account registered through: rows that
	 * name it as `contactRef` while still `invited`.
	 *
	 * @param string $contactRef The new account.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function heldFor(string $contactRef, string $organisation): array {
		if ($contactRef === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: PortalContactService::REGISTER,
			schema: PortalContactService::SCHEMA,
			scopeField: 'contactRef',
			subjectRef: $contactRef,
			organisation: $organisation,
			limit: 20
		);

		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => (string)($row['state'] ?? '') === 'invited' && (string)($row['contactRef'] ?? '') === $contactRef
			)
		);
	}//end heldFor()
}//end class
