<?php

/**
 * Portaliq Portal Contact Service
 *
 * A resident's own contacts: the list, invitations by e-mail, requests that
 * both sides approve, and removal.
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
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * A link is two rows, one per side, so each side reads only its own. Approval
 * sets both rows to `approved`. A contact sees a name and messages, never a
 * case: this service reads and writes `portalContact` and nothing else of the
 * resident's.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- reader, writer, mailer and the random source.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) -- one method per action a resident takes on a contact.
 *
 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
 */
class PortalContactService {

	public const REGISTER = 'portaliq';

	public const SCHEMA = 'portalContact';

	public const SENT = 'sent';

	public const INVALID = 'invalid';

	public const LIMIT = 'limit';

	public const DUPLICATE = 'duplicate';

	public const NOT_FOUND = 'not_found';

	public const FAILED = 'failed';

	/**
	 * How many invitations a resident may send in one day.
	 *
	 * @var int
	 */
	public const DAILY_LIMIT = 10;

	/**
	 * How long an invitation link works.
	 *
	 * @var string
	 */
	private const TTL = 'P14D';

	/**
	 * The longest message sent with an invitation.
	 *
	 * @var int
	 */
	private const MAX_MESSAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads contact rows and accounts.
	 * @param PortalObjectWriter $writer Writes contact rows.
	 * @param ISecureRandom $random Mints the one-time secret.
	 * @param PortalIdentityMailer $mailer Mails the invitation.
	 * @param PortalAccountLookup $accounts Reads an account's name.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
		private readonly PortalIdentityMailer $mailer,
		private readonly PortalAccountLookup $accounts,
	) {
	}//end __construct()

	/**
	 * The resident's contacts, and what waits for an answer.
	 *
	 * @param string $owner The resident's subject reference.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed> `incoming`, `outgoing` and `contacts` lists of rows, and `counts` per role.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function overview(string $owner, string $organisation): array {
		$overview = ['incoming' => [], 'outgoing' => [], 'contacts' => [], 'counts' => ['all' => 0, 'begeleider' => 0, 'contact' => 0, 'organisatie' => 0]];
		foreach ($this->rowsOf(owner: $owner, organisation: $organisation) as $row) {
			$shown = $this->shown(row: $row);
			switch ((string)($row['state'] ?? '')) {
				case 'requested':
					$overview['incoming'][] = $shown;
					break;
				case 'invited':
				case 'declined':
					$overview['outgoing'][] = $shown;
					break;
				case 'approved':
					$overview['contacts'][] = $shown;
					$overview['counts']['all']++;
					$role = $shown['role'];
					if (isset($overview['counts'][$role]) === true) {
						$overview['counts'][$role]++;
					}

					break;
				default:
					break;
			}
		}

		return $overview;
	}//end overview()

	/**
	 * Invite someone by e-mail address.
	 *
	 * An address with an account gets an approval request, an address without
	 * one a mailed link valid for 14 days. The caller learns only that it was
	 * sent, so the answer does not say whether the address has an account.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $email The address.
	 * @param string $message An optional short message.
	 * @param DateTimeImmutable|null $now The moment, for the daily limit and the expiry.
	 *
	 * @return string One of SENT, INVALID, LIMIT, DUPLICATE or FAILED.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function invite(array $subject, string $email, string $message, ?DateTimeImmutable $now=null): string {
		$owner        = (string)($subject['subjectRef'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		$email        = strtolower(trim($email));
		$message      = trim($message);
		if ($owner === '' || $organisation === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($message) > self::MAX_MESSAGE) {
			return self::INVALID;
		}

		$now  = ($now ?? new DateTimeImmutable());
		$rows = $this->rowsOf(owner: $owner, organisation: $organisation);
		if ($this->sentToday(rows: $rows, now: $now) >= self::DAILY_LIMIT) {
			return self::LIMIT;
		}

		$account = $this->accountByEmail(email: $email, organisation: $organisation);
		if ($account !== null && (string)($account['subjectRef'] ?? '') === $owner) {
			return self::INVALID;
		}

		if ($this->alreadyOpenOrLinked(rows: $rows, email: $email, account: $account) === true) {
			return self::DUPLICATE;
		}

		if ($account !== null) {
			return $this->request(subject: $subject, account: $account, message: $message, now: $now);
		}

		return $this->mailInvitation(subject: $subject, email: $email, message: $message, now: $now, row: null);
	}//end invite()

	/**
	 * Send an invitation again with a new link and a new 14 days.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The invitation row.
	 * @param DateTimeImmutable|null $now The moment.
	 *
	 * @return string SENT, NOT_FOUND or FAILED.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function resend(array $subject, string $id, ?DateTimeImmutable $now=null): string {
		$row = $this->ownRow(subject: $subject, id: $id, states: ['invited']);
		if ($row === null) {
			return self::NOT_FOUND;
		}

		$now = ($now ?? new DateTimeImmutable());
		if ((string)($row['email'] ?? '') === '') {
			// An approval request to an account has no link to send again.
			$this->update(subject: $subject, id: $id, data: ['sentAt' => $now->format(DATE_ATOM)]);
			return self::SENT;
		}

		return $this->mailInvitation(subject: $subject, email: (string)$row['email'], message: (string)($row['message'] ?? ''), now: $now, row: $row);
	}//end resend()

	/**
	 * Take an invitation or request back.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The row.
	 *
	 * @return string SENT when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function withdraw(array $subject, string $id): string {
		return $this->endLink(subject: $subject, id: $id, from: ['invited'], to: 'withdrawn', theirs: 'requested');
	}//end withdraw()

	/**
	 * Remove an approved contact: both rows end, earlier messages stay.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The row.
	 *
	 * @return string SENT when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function remove(array $subject, string $id): string {
		return $this->endLink(subject: $subject, id: $id, from: ['approved'], to: 'withdrawn', theirs: 'approved');
	}//end remove()

	/**
	 * Answer a request from another account.
	 *
	 * Accepting sets both rows to `approved`; declining sets both to
	 * `declined`, which the requester reads only as "not accepted".
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The request row.
	 * @param bool $accept Whether to accept.
	 *
	 * @return string SENT when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function respond(array $subject, string $id, bool $accept): string {
		if ($accept === true) {
			return $this->endLink(subject: $subject, id: $id, from: ['requested'], to: 'approved', theirs: 'invited');
		}

		return $this->endLink(subject: $subject, id: $id, from: ['requested'], to: 'withdrawn', theirs: 'invited', theirTo: 'declined');
	}//end respond()

	/**
	 * A person who followed an invitation link and has an account now becomes
	 * the inviter's contact on both sides, without a second approval.
	 *
	 * @param array<string, mixed> $subject The new account's session subject.
	 * @param string $token The secret from the mail.
	 * @param string $displayName The new account's name.
	 * @param DateTimeImmutable|null $now The moment, for the expiry.
	 *
	 * @return string SENT when joined, else NOT_FOUND (also for an expired or used link).
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function acceptInvitation(array $subject, string $token, string $displayName, ?DateTimeImmutable $now=null): string {
		$newRef       = (string)($subject['subjectRef'] ?? '');
		$organisation = (string)($subject['organisation'] ?? '');
		if ($token === '' || $newRef === '' || $organisation === '') {
			return self::NOT_FOUND;
		}

		$row = $this->byToken(token: $token, organisation: $organisation);
		$now = ($now ?? new DateTimeImmutable());
		if ($row === null || (string)($row['state'] ?? '') !== 'invited' || (string)($row['owner'] ?? '') === $newRef
			|| $this->expired(row: $row, now: $now) === true
		) {
			return self::NOT_FOUND;
		}

		$inviter = (string)$row['owner'];
		$written = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: $inviter,
			organisation: $organisation,
			id: $this->idOf(row: $row),
			data: ['contactRef' => $newRef, 'displayName' => $displayName, 'state' => 'approved', 'tokenHash' => '', 'email' => '']
		);
		if ($written === null) {
			return self::FAILED;
		}

		$inviterAccount = $this->accounts->bySubjectRef(subjectRef: $inviter);
		$this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: $newRef,
			organisation: $organisation,
			data: ['contactRef' => $inviter, 'role' => 'contact', 'displayName' => (string)($inviterAccount['displayName'] ?? ''), 'state' => 'approved']
		);

		return self::SENT;
	}//end acceptInvitation()

	/**
	 * The approved contacts' references of a resident, for a message thread.
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, string> The other accounts' subject references.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function approvedRefs(string $owner, string $organisation): array {
		$refs = [];
		foreach ($this->rowsOf(owner: $owner, organisation: $organisation) as $row) {
			if ((string)($row['state'] ?? '') === 'approved' && (string)($row['contactRef'] ?? '') !== '') {
				$refs[] = (string)$row['contactRef'];
			}
		}

		return $refs;
	}//end approvedRefs()

	/**
	 * Ask an existing account to become a contact: two rows, one per side.
	 *
	 * @param array<string, mixed> $subject The inviter.
	 * @param array<string, mixed> $account The other account.
	 * @param string $message The message.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return string SENT or FAILED.
	 */
	private function request(array $subject, array $account, string $message, DateTimeImmutable $now): string {
		$owner        = (string)$subject['subjectRef'];
		$organisation = (string)$subject['organisation'];
		$theirs       = (string)$account['subjectRef'];
		$mine         = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: $owner,
			organisation: $organisation,
			data: [
				'contactRef'  => $theirs,
				'role'        => 'contact',
				'displayName' => (string)($account['displayName'] ?? ''),
				'state'       => 'invited',
				'message'     => $message,
				'sentAt'      => $now->format(DATE_ATOM),
			]
		);
		if ($mine === null) {
			return self::FAILED;
		}

		$this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: $theirs,
			organisation: $organisation,
			data: [
				'contactRef'  => $owner,
				'role'        => 'contact',
				'displayName' => (string)($subject['displayName'] ?? ''),
				'state'       => 'requested',
				'message'     => $message,
				'sentAt'      => $now->format(DATE_ATOM),
			]
		);

		return self::SENT;
	}//end request()

	/**
	 * Mint a link, store its hash and mail it. A mail that did not leave
	 * leaves the invitation in place to send again.
	 *
	 * @param array<string, mixed> $subject The inviter.
	 * @param string $email The address.
	 * @param string $message The message.
	 * @param DateTimeImmutable $now The moment.
	 * @param array<string, mixed>|null $row The existing invitation to renew, or null for a new one.
	 *
	 * @return string SENT or FAILED.
	 */
	private function mailInvitation(array $subject, string $email, string $message, DateTimeImmutable $now, ?array $row): string {
		$owner        = (string)$subject['subjectRef'];
		$organisation = (string)$subject['organisation'];
		$token        = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		$data         = [
			'tokenHash' => hash('sha256', $token),
			'sentAt'    => $now->format(DATE_ATOM),
			'expiresAt' => $now->add(new DateInterval(self::TTL))->format(DATE_ATOM),
		];

		if ($row === null) {
			$saved = $this->writer->createObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: 'owner',
				subjectRef: $owner,
				organisation: $organisation,
				data: $data + ['role' => 'contact', 'displayName' => $email, 'state' => 'invited', 'email' => $email, 'message' => $message]
			);
		} else {
			$saved = $this->update(subject: $subject, id: $this->idOf(row: $row), data: $data);
		}

		if ($saved === null) {
			return self::FAILED;
		}

		$sent = $this->mailer->send(
			template: PortalIdentityMailer::TEMPLATE_CONTACT_INVITATION,
			email: $email,
			secret: $token,
			organisation: $organisation,
			portal: null,
			details: ['inviter' => (string)($subject['displayName'] ?? ''), 'message' => $message]
		);
		if ($sent === false) {
			return self::FAILED;
		}

		return self::SENT;
	}//end mailInvitation()

	/**
	 * End both sides of a link: the resident's row and the other side's.
	 *
	 * @param array<string, mixed> $subject The resident.
	 * @param string $id The resident's row.
	 * @param array<int, string> $from The states the row may be in.
	 * @param string $to The state both rows end in.
	 * @param string $theirs The state the other side's row is expected to hold.
	 * @param string $theirTo The state the other side's row ends in, when it is not $to.
	 *
	 * @return string SENT or NOT_FOUND.
	 */
	private function endLink(array $subject, string $id, array $from, string $to, string $theirs, string $theirTo=''): string {
		$row = $this->ownRow(subject: $subject, id: $id, states: $from);
		if ($row === null) {
			return self::NOT_FOUND;
		}

		$this->update(subject: $subject, id: $id, data: ['state' => $to, 'tokenHash' => '']);
		$other = (string)($row['contactRef'] ?? '');
		if ($theirTo === '') {
			$theirTo = $to;
		}

		if ($other === '') {
			return self::SENT;
		}

		foreach ($this->rowsOf(owner: $other, organisation: (string)$subject['organisation']) as $counterpart) {
			if ((string)($counterpart['contactRef'] ?? '') === (string)$subject['subjectRef'] && (string)($counterpart['state'] ?? '') === $theirs) {
				$this->writer->updateObject(
					register: self::REGISTER,
					schema: self::SCHEMA,
					scopeField: 'owner',
					subjectRef: $other,
					organisation: (string)$subject['organisation'],
					id: $this->idOf(row: $counterpart),
					data: ['state' => $theirTo]
				);
			}
		}

		return self::SENT;
	}//end endLink()

	/**
	 * Write fields on one of the resident's own rows.
	 *
	 * @param array<string, mixed> $subject The resident.
	 * @param string $id The row.
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return array<string, mixed>|null The written row, or null.
	 */
	private function update(array $subject, string $id, array $data): ?array {
		return $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: (string)$subject['subjectRef'],
			organisation: (string)$subject['organisation'],
			id: $id,
			data: $data
		);
	}//end update()

	/**
	 * One of the resident's own rows, in one of some states, or null.
	 *
	 * @param array<string, mixed> $subject The resident.
	 * @param string $id The row id.
	 * @param array<int, string> $states The states it may be in.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function ownRow(array $subject, string $id, array $states): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->rowsOf(owner: (string)($subject['subjectRef'] ?? ''), organisation: (string)($subject['organisation'] ?? '')) as $row) {
			if ($this->idOf(row: $row) === $id && in_array((string)($row['state'] ?? ''), $states, true) === true) {
				return $row;
			}
		}

		return null;
	}//end ownRow()

	/**
	 * Every row a resident holds, read through the scoped reader.
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(string $owner, string $organisation): array {
		if ($owner === '') {
			return [];
		}

		return $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'owner',
			subjectRef: $owner,
			organisation: $organisation,
			limit: 200
		);
	}//end rowsOf()

	/**
	 * The active account that holds an address, or null.
	 *
	 * @param string $email The address.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null The account.
	 */
	private function accountByEmail(string $email, string $organisation): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: 'portalAccount',
			scopeField: 'email',
			subjectRef: $email,
			organisation: $organisation,
			limit: 5
		);
		foreach ($rows as $row) {
			$active = ((string)($row['status'] ?? '') === 'active' && (string)($row['subjectRef'] ?? '') !== '');
			if ($active === true && strtolower((string)($row['email'] ?? '')) === $email) {
				return $row;
			}
		}

		return null;
	}//end accountByEmail()

	/**
	 * The invitation a link's secret belongs to.
	 *
	 * @param string $token The secret.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	private function byToken(string $token, string $organisation): ?array {
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'tokenHash',
			subjectRef: hash('sha256', $token),
			organisation: $organisation,
			limit: 2
		);

		return ($rows[0] ?? null);
	}//end byToken()

	/**
	 * How many invitations the rows hold from today.
	 *
	 * @param array<int, array<string, mixed>> $rows The resident's rows.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return int The count; a withdrawn invitation still counts, it was sent.
	 */
	private function sentToday(array $rows, DateTimeImmutable $now): int {
		$day   = $now->format('Y-m-d');
		$count = 0;
		foreach ($rows as $row) {
			if (str_starts_with((string)($row['sentAt'] ?? ''), $day) === true && (string)($row['state'] ?? '') !== 'requested') {
				$count++;
			}
		}

		return $count;
	}//end sentToday()

	/**
	 * Whether the address already has an open invitation, or the account is already linked or asked.
	 *
	 * @param array<int, array<string, mixed>> $rows The resident's rows.
	 * @param string $email The address.
	 * @param array<string, mixed>|null $account The account behind the address.
	 *
	 * @return bool
	 */
	private function alreadyOpenOrLinked(array $rows, string $email, ?array $account): bool {
		foreach ($rows as $row) {
			if (in_array((string)($row['state'] ?? ''), ['invited', 'requested', 'approved'], true) === false) {
				continue;
			}

			if (strtolower((string)($row['email'] ?? '')) === $email) {
				return true;
			}

			if ($account !== null && (string)($row['contactRef'] ?? '') === (string)$account['subjectRef']) {
				return true;
			}
		}

		return false;
	}//end alreadyOpenOrLinked()

	/**
	 * Whether an invitation's link has lapsed.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return bool True when it has expired or carries no expiry.
	 */
	private function expired(array $row, DateTimeImmutable $now): bool {
		$expires = (string)($row['expiresAt'] ?? '');
		if ($expires === '') {
			return true;
		}

		return new DateTimeImmutable($expires) <= $now;
	}//end expired()

	/**
	 * A row as the browser may see it: no token hash, and a declined request
	 * reads only as not accepted.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The row to show.
	 */
	private function shown(array $row): array {
		return [
			'id'          => $this->idOf(row: $row),
			'displayName' => (string)($row['displayName'] ?? ''),
			'line'        => (string)($row['line'] ?? ''),
			'role'        => (string)($row['role'] ?? 'contact'),
			'state'       => (string)($row['state'] ?? ''),
			'email'       => (string)($row['email'] ?? ''),
			'message'     => (string)($row['message'] ?? ''),
			'sentAt'      => (string)($row['sentAt'] ?? ''),
			'expiresAt'   => (string)($row['expiresAt'] ?? ''),
		];
	}//end shown()

	/**
	 * A row's identifier, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['uuid'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()
}//end class
