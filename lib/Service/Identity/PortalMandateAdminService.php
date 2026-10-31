<?php

/**
 * Portaliq Mandate Admin Service (site-mandates-the-represented-manage)
 *
 * The represented party manages who may act for it, and a holder stops its
 * own mandate. Portaliq holds no BSN, so nobody is looked up: a party invites
 * an email address, and the mandate is written only when the invitee signs in
 * and accepts. Every call names the party from the session, and a row of any
 * other party answers as a missing one.
 *
 * A party is typed: `kvk:` and eight digits, or `subject:` and a reference.
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
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Lists, gives, accepts, ends and stops mandates for one party.
 *
 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md
 */
class PortalMandateAdminService {
	public const REFUSED = 'refused';

	public const NOT_FOUND = 'not_found';

	public const BAD_EMAIL = 'bad_email';

	public const BAD_END_DATE = 'bad_end_date';

	public const OWN_INVITATION = 'own_invitation';

	public const GONE = 'gone';

	private const REGISTER = MandateStore::REGISTER;

	private const MANDATES = MandateStore::MANDATES;

	private const INVITATIONS = MandateStore::INVITATIONS;

	private const WINDOW = 'P14D';

	/**
	 * Who holds and who is represented.
	 *
	 * @var MandateParties
	 */
	private readonly MandateParties $parties;

	/**
	 * Whether an end has passed, and the end of a day.
	 *
	 * @var MandateDays
	 */
	private readonly MandateDays $days;

	/**
	 * The mandate and invitation rows.
	 *
	 * @var MandateStore
	 */
	private readonly MandateStore $store;

	/**
	 * The read side: mandates given and held.
	 *
	 * @var MandateListing
	 */
	private readonly MandateListing $listing;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The scoped reader.
	 * @param PortalObjectWriter $writer The scoped writer.
	 * @param ISecureRandom      $random Mints the invitation secret.
	 */
	public function __construct(
		PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
		$this->parties = new MandateParties();
		$this->days    = new MandateDays();
		$this->store   = new MandateStore(reader: $reader, writer: $writer);
		$this->listing = new MandateListing(store: $this->store, parties: $this->parties, days: $this->days);
	}//end __construct()

	/**
	 * Every mandate and open invitation given on this party's behalf.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return array<int, array<string, mixed>> `{kind, id, label, caseTypes, expiresAt, state, email|holder}`.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-the-represented-party-must-see-who-may-act-for-it-req-smr-002
	 */
	public function given(string $party, string $organisation, ?DateTimeImmutable $now = null): array {
		return $this->listing->given(party: $party, organisation: $organisation, now: $now);
	}//end given()

	/**
	 * Send an invitation to act for the party. Nobody is looked up.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param string                 $invitedBy    The inviter's subject reference.
	 * @param array<string, mixed>   $terms        `email`, `caseTypes[]`, `label`, `expiresAt` (a future day or '').
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return array{token: string}|string The secret to mail, or one of BAD_EMAIL, BAD_END_DATE, REFUSED.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-party-must-invite-never-look-up-the-person-it-authorises-req-smr-003
	 */
	public function invite(string $party, string $organisation, string $invitedBy, array $terms, ?DateTimeImmutable $now = null): array|string {
		$moment = ($now ?? new DateTimeImmutable());
		$email  = trim((string)($terms['email'] ?? ''));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return self::BAD_EMAIL;
		}

		$end = trim((string)($terms['expiresAt'] ?? ''));
		if ($end !== '' && $this->days->futureDay(value: $end, now: $moment) === false) {
			return self::BAD_END_DATE;
		}

		$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		if ($party === '' || $organisation === '' || $invitedBy === '' || $token === '') {
			return self::REFUSED;
		}

		$caseTypes = array_values(array_filter((array)($terms['caseTypes'] ?? []), 'is_string'));
		$created   = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::INVITATIONS,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'email' => $email,
				'organisation' => $organisation,
				'audience' => 'client',
				'tokenHash' => hash('sha256', $token),
				'state' => 'sent',
				'invitedBy' => $invitedBy,
				'sentAt' => $moment->format(DATE_ATOM),
				'expiresAt' => $moment->add(new DateInterval(self::WINDOW))->format(DATE_ATOM),
				'onBehalfOf' => $party,
				'mandate' => [
					'caseTypes' => $caseTypes,
					'label' => mb_substr(trim((string)($terms['label'] ?? '')), 0, 200),
					'expiresAt' => $end,
				],
			]
		);
		if ($created === null) {
			return self::REFUSED;
		}

		return ['token' => $token];
	}//end invite()

	/**
	 * The invitee signs in and accepts: the mandate is written for the
	 * account that accepted, with the terms the inviter set. The inviter
	 * cannot accept their own invitation, and one invitation makes one mandate.
	 *
	 * @param string                 $token   The secret from the mail.
	 * @param array<string, mixed>   $subject The accepting session.
	 * @param DateTimeImmutable|null $now     The moment.
	 *
	 * @return array{id: string, holder: string}|string The mandate, or OWN_INVITATION, GONE, REFUSED.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-party-must-invite-never-look-up-the-person-it-authorises-req-smr-003
	 */
	public function accept(string $token, array $subject, ?DateTimeImmutable $now = null): array|string {
		$moment = ($now ?? new DateTimeImmutable());
		$ref    = (string)($subject['subjectRef'] ?? '');
		$row    = $this->store->invitationByToken(token: $token);
		if ($row === null || $ref === '') {
			return self::GONE;
		}

		$open = in_array((string)($row['state'] ?? 'sent'), ['sent', 'opened'], true);
		if ($open === false || $this->days->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
			return self::GONE;
		}

		if ((string)($row['invitedBy'] ?? '') === $ref) {
			return self::OWN_INVITATION;
		}

		$holders = $this->parties->holdersOf(subject: $subject);
		$holder  = (string)end($holders);
		$terms   = (array)($row['mandate'] ?? []);
		$mandate = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::MANDATES,
			scopeField: '',
			subjectRef: '',
			organisation: (string)($row['organisation'] ?? ''),
			data: [
				'subjectRef' => $ref,
				'holder' => $holder,
				'organisation' => (string)($row['organisation'] ?? ''),
				'onBehalfOf' => (string)($row['onBehalfOf'] ?? ''),
				'label' => (string)($terms['label'] ?? ''),
				'caseTypes' => array_values((array)($terms['caseTypes'] ?? [])),
				'reach' => 'organisation',
				'status' => 'active',
				'grantedBy' => (string)($row['invitedBy'] ?? ''),
				'grantedAt' => $moment->format(DATE_ATOM),
				'expiresAt' => $this->days->endOfDay(day: (string)($terms['expiresAt'] ?? '')),
				'invitationId' => $this->store->idOf(row: $row),
			]
		);
		if ($mandate === null) {
			return self::REFUSED;
		}

		// Spent only after the mandate exists, and spent once.
		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::INVITATIONS,
			scopeField: 'organisation',
			subjectRef: (string)($row['organisation'] ?? ''),
			organisation: (string)($row['organisation'] ?? ''),
			id: $this->store->idOf(row: $row),
			data: ['state' => 'accepted', 'subjectRef' => $ref]
		);

		return ['id' => $this->store->idOf(row: $mandate), 'holder' => $holder];
	}//end accept()

	/**
	 * Revoke a mandate given on the party's behalf.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $actor           Who ends it (a subject reference).
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function revoke(string $party, string $organisation, string $id, string $actor, ?DateTimeImmutable $now = null): string {
		$row = $this->store->mandate(id: $id, organisation: $organisation);
		if ($row === null || $this->parties->typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party) {
			return self::NOT_FOUND;
		}

		return $this->end(row: $row, actor: $actor, now: ($now ?? new DateTimeImmutable()));
	}//end revoke()

	/**
	 * The holder stops its own mandate.
	 *
	 * @param array<int, string>     $holders      The parties the session carries.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $actor           Who stops it.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function stop(array $holders, string $organisation, string $id, string $actor, ?DateTimeImmutable $now = null): string {
		$row = $this->store->mandate(id: $id, organisation: $organisation);
		if ($row === null || in_array($this->parties->holderOf(row: $row), $holders, true) === false) {
			return self::NOT_FOUND;
		}

		return $this->end(row: $row, actor: $actor, now: ($now ?? new DateTimeImmutable()));
	}//end stop()

	/**
	 * Withdraw an open invitation given on the party's behalf.
	 *
	 * @param string $party        The session's party.
	 * @param string $organisation The tenant.
	 * @param string $id           The invitation.
	 *
	 * @return string '' when done, NOT_FOUND, or GONE when it is no longer open.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function revokeInvitation(string $party, string $organisation, string $id): string {
		foreach ($this->store->rows(schema: self::INVITATIONS, organisation: $organisation) as $row) {
			if ($this->store->idOf(row: $row) !== $id || $this->parties->typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party) {
				continue;
			}

			if (in_array((string)($row['state'] ?? 'sent'), ['sent', 'opened'], true) === false) {
				return self::GONE;
			}

			$this->store->update(schema: self::INVITATIONS, row: $row, data: ['state' => 'revoked']);

			return '';
		}

		return self::NOT_FOUND;
	}//end revokeInvitation()

	/**
	 * Set or move a mandate's end date, never to the past, never on a mandate
	 * that has ended.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $day          The new end day, `Y-m-d`.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND, BAD_END_DATE or GONE.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function setExpiry(string $party, string $organisation, string $id, string $day, ?DateTimeImmutable $now = null): string {
		$moment = ($now ?? new DateTimeImmutable());
		$row    = $this->store->mandate(id: $id, organisation: $organisation);
		if ($row === null || $this->parties->typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party) {
			return self::NOT_FOUND;
		}

		if (($row['status'] ?? 'active') !== 'active' || $this->days->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
			return self::GONE;
		}

		if ($this->days->futureDay(value: $day, now: $moment) === false) {
			return self::BAD_END_DATE;
		}

		$this->store->update(schema: self::MANDATES, row: $row, data: ['expiresAt' => $this->days->endOfDay(day: $day)]);

		return '';
	}//end setExpiry()

	/**
	 * The mandates the session holds, in the shape "Uw machtiging" shows.
	 *
	 * @param array<int, string>     $holders      The parties the session carries.
	 * @param string                 $organisation The tenant.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function held(array $holders, string $organisation, ?DateTimeImmutable $now = null): array {
		return $this->listing->held(holders: $holders, organisation: $organisation, now: $now);
	}//end held()

	/**
	 * Who holds a mandate: its `holder`, else the account it was written for.
	 *
	 * @param array<string, mixed> $row The mandate.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-company-must-hold-the-mandate-it-accepts-req-smr-005
	 */
	public function holderOf(array $row): string {
		return $this->parties->holderOf(row: $row);
	}//end holderOf()

	/**
	 * Mark a mandate revoked, once; final.
	 *
	 * @param array<string, mixed> $row The mandate.
	 * @param string               $actor Who ends it.
	 * @param DateTimeImmutable    $now The moment.
	 *
	 * @return string
	 */
	private function end(array $row, string $actor, DateTimeImmutable $now): string {
		if (($row['status'] ?? 'active') === 'revoked') {
			return '';
		}

		$ended = ['status' => 'revoked', 'revokedBy' => $actor, 'revokedAt' => $now->format(DATE_ATOM)];
		$this->store->update(schema: self::MANDATES, row: $row, data: $ended);

		return '';
	}//end end()

}//end class
