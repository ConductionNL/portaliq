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

	private const REGISTER = 'portaliq';

	private const MANDATES = 'portalMandate';

	private const INVITATIONS = 'portalInvitation';

	private const WINDOW = 'P14D';

	private const LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader The scoped reader.
	 * @param PortalObjectWriter $writer The scoped writer.
	 * @param ISecureRandom      $random Mints the invitation secret.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * The party a session may manage, from the session alone: the company of
	 * an eHerkenning sign-in, the person of any other. An eHerkenning session
	 * that does not carry its company's number manages nothing.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return string|null `kvk:<8 digits>`, `subject:<ref>`, or null.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d1-who-is-the-represented-party
	 */
	public static function partyOf(array $subject): ?string {
		if (($subject['provider'] ?? '') === 'eherkenning') {
			$kvk = (string)($subject['kvk'] ?? '');
			if (preg_match('/^\d{8}$/', $kvk) === 1) {
				return 'kvk:' . $kvk;
			}

			return null;
		}

		$ref = (string)($subject['subjectRef'] ?? '');
		if ($ref === '') {
			return null;
		}

		return 'subject:' . $ref;
	}//end partyOf()

	/**
	 * The parties a session carries as a holder: itself, and its company.
	 *
	 * @param array<string, mixed> $subject The resolved session subject.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/design.md#d7-who-holds-a-mandate-decided
	 */
	public static function holdersOf(array $subject): array {
		$out = [];
		$ref = (string)($subject['subjectRef'] ?? '');
		if ($ref !== '') {
			$out[] = 'subject:' . $ref;
		}

		$kvk = (string)($subject['kvk'] ?? '');
		if (($subject['provider'] ?? '') === 'eherkenning' && preg_match('/^\d{8}$/', $kvk) === 1) {
			$out[] = 'kvk:' . $kvk;
		}

		return $out;
	}//end holdersOf()

	/**
	 * A party in its typed form: an untyped value of eight digits is a KVK
	 * number, any other untyped value a subject reference.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-a-mandate-must-name-its-party-in-a-typed-form-req-smr-001
	 */
	public static function typed(string $value): string {
		$value = trim($value);
		if ($value === '' || str_starts_with($value, 'kvk:') === true || str_starts_with($value, 'subject:') === true) {
			return $value;
		}

		if (preg_match('/^\d{8}$/', $value) === 1) {
			return 'kvk:' . $value;
		}

		return 'subject:' . $value;
	}//end typed()

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
		$moment = ($now ?? new DateTimeImmutable());
		$out    = [];
		foreach ($this->rows(schema: self::MANDATES, organisation: $organisation) as $row) {
			if (self::typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party || ($row['status'] ?? 'active') === 'revoked') {
				continue;
			}

			$mandateState = 'active';
			if ($this->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
				$mandateState = 'expired';
			}

			$out[] = [
				'kind' => 'mandate',
				'id' => $this->idOf(row: $row),
				'holder' => $this->holderOf(row: $row),
				'label' => (string)($row['label'] ?? ''),
				'caseTypes' => array_values((array)($row['caseTypes'] ?? [])),
				'expiresAt' => (string)($row['expiresAt'] ?? ''),
				'state' => $mandateState,
			];
		}

		foreach ($this->rows(schema: self::INVITATIONS, organisation: $organisation) as $row) {
			$state = (string)($row['state'] ?? 'sent');
			if (self::typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party || in_array($state, ['sent', 'opened'], true) === false) {
				continue;
			}

			$terms = (array)($row['mandate'] ?? []);
			$out[] = [
				'kind' => 'invitation',
				'id' => $this->idOf(row: $row),
				'email' => (string)($row['email'] ?? ''),
				'label' => (string)($terms['label'] ?? ''),
				'caseTypes' => array_values((array)($terms['caseTypes'] ?? [])),
				'expiresAt' => (string)($terms['expiresAt'] ?? ''),
				'state' => 'pending',
			];
		}

		return $out;
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
		if ($end !== '' && $this->futureDay(value: $end, now: $moment) === false) {
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
		$row    = $this->invitationByToken(token: $token);
		if ($row === null || $ref === '') {
			return self::GONE;
		}

		$open = in_array((string)($row['state'] ?? 'sent'), ['sent', 'opened'], true);
		if ($open === false || $this->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
			return self::GONE;
		}

		if ((string)($row['invitedBy'] ?? '') === $ref) {
			return self::OWN_INVITATION;
		}

		$holders = self::holdersOf(subject: $subject);
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
				'expiresAt' => $this->endOfDay(day: (string)($terms['expiresAt'] ?? '')),
				'invitationId' => $this->idOf(row: $row),
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
			id: $this->idOf(row: $row),
			data: ['state' => 'accepted', 'subjectRef' => $ref]
		);

		return ['id' => $this->idOf(row: $mandate), 'holder' => $holder];
	}//end accept()

	/**
	 * Revoke a mandate given on the party's behalf.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $by           Who ends it (a subject reference).
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function revoke(string $party, string $organisation, string $id, string $by, ?DateTimeImmutable $now = null): string {
		$row = $this->mandate(id: $id, organisation: $organisation);
		if ($row === null || self::typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party) {
			return self::NOT_FOUND;
		}

		return $this->end(row: $row, by: $by, now: ($now ?? new DateTimeImmutable()));
	}//end revoke()

	/**
	 * The holder stops its own mandate.
	 *
	 * @param array<int, string>     $holders      The parties the session carries.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $by           Who stops it.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function stop(array $holders, string $organisation, string $id, string $by, ?DateTimeImmutable $now = null): string {
		$row = $this->mandate(id: $id, organisation: $organisation);
		if ($row === null || in_array($this->holderOf(row: $row), $holders, true) === false) {
			return self::NOT_FOUND;
		}

		return $this->end(row: $row, by: $by, now: ($now ?? new DateTimeImmutable()));
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
		foreach ($this->rows(schema: self::INVITATIONS, organisation: $organisation) as $row) {
			if ($this->idOf(row: $row) !== $id || self::typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party) {
				continue;
			}

			if (in_array((string)($row['state'] ?? 'sent'), ['sent', 'opened'], true) === false) {
				return self::GONE;
			}

			$this->update(schema: self::INVITATIONS, row: $row, data: ['state' => 'revoked']);

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
		$row    = $this->mandate(id: $id, organisation: $organisation);
		if ($row === null || self::typed(value: (string)($row['onBehalfOf'] ?? '')) !== $party) {
			return self::NOT_FOUND;
		}

		if (($row['status'] ?? 'active') !== 'active' || $this->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
			return self::GONE;
		}

		if ($this->futureDay(value: $day, now: $moment) === false) {
			return self::BAD_END_DATE;
		}

		$this->update(schema: self::MANDATES, row: $row, data: ['expiresAt' => $this->endOfDay(day: $day)]);

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
		$moment = ($now ?? new DateTimeImmutable());
		$out    = [];
		foreach ($this->rows(schema: self::MANDATES, organisation: $organisation) as $row) {
			if (in_array($this->holderOf(row: $row), $holders, true) === false || ($row['status'] ?? 'active') !== 'active') {
				continue;
			}

			if ($this->expired(value: (string)($row['expiresAt'] ?? ''), now: $moment) === true) {
				continue;
			}

			$out[] = [
				'id' => $this->idOf(row: $row),
				'label' => (string)($row['label'] ?? ''),
				'onBehalfOf' => self::typed(value: (string)($row['onBehalfOf'] ?? '')),
				'expiresAt' => (string)($row['expiresAt'] ?? ''),
				'grantedBy' => (string)($row['grantedBy'] ?? ''),
				'grantedAt' => (string)($row['grantedAt'] ?? ''),
			];
		}

		return $out;
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
		$holder = trim((string)($row['holder'] ?? ''));
		if ($holder !== '') {
			return self::typed(value: $holder);
		}

		$ref = trim((string)($row['subjectRef'] ?? ''));
		if ($ref === '') {
			return '';
		}

		return 'subject:' . $ref;
	}//end holderOf()

	/**
	 * Mark a mandate revoked, once; final.
	 *
	 * @param array<string, mixed> $row The mandate.
	 * @param string               $by  Who ends it.
	 * @param DateTimeImmutable    $now The moment.
	 *
	 * @return string
	 */
	private function end(array $row, string $by, DateTimeImmutable $now): string {
		if (($row['status'] ?? 'active') === 'revoked') {
			return '';
		}

		$this->update(schema: self::MANDATES, row: $row, data: ['status' => 'revoked', 'revokedBy' => $by, 'revokedAt' => $now->format(DATE_ATOM)]);

		return '';
	}//end end()

	/**
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $data   The change.
	 *
	 * @return void
	 */
	private function update(string $schema, array $row, array $data): void {
		$organisation = (string)($row['organisation'] ?? '');
		$this->writer->updateObject(
			register: self::REGISTER,
			schema: $schema,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			id: $this->idOf(row: $row),
			data: $data
		);
	}//end update()

	/**
	 * @param string $id           The mandate id.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed>|null
	 */
	private function mandate(string $id, string $organisation): ?array {
		if ($id === '') {
			return null;
		}

		foreach ($this->rows(schema: self::MANDATES, organisation: $organisation) as $row) {
			if ($this->idOf(row: $row) === $id) {
				return $row;
			}
		}

		return null;
	}//end mandate()

	/**
	 * @param string $token The invitation secret.
	 *
	 * @return array<string, mixed>|null
	 */
	private function invitationByToken(string $token): ?array {
		if ($token === '') {
			return null;
		}

		$hash = hash('sha256', $token);
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::INVITATIONS,
			scopeField: 'tokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['tokenHash'] ?? ''), $hash) === true && isset($row['mandate']) === true) {
				return $row;
			}
		}

		return null;
	}//end invitationByToken()

	/**
	 * Every row of one schema in one tenant, re-checked against the tenant.
	 *
	 * @param string $schema       The schema.
	 * @param string $organisation The tenant.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(string $schema, string $organisation): array {
		if ($organisation === '') {
			return [];
		}

		$out = [];
		foreach ($this->reader->readCollection(
			register: self::REGISTER,
			schema: $schema,
			scopeField: 'organisation',
			subjectRef: $organisation,
			organisation: $organisation,
			limit: self::LIMIT
		) as $row) {
			if (is_array($row) === true && ($row['organisation'] ?? '') === $organisation) {
				$out[] = $row;
			}
		}

		return $out;
	}//end rows()

	/**
	 * @param array<string, mixed> $row A stored row.
	 *
	 * @return string
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		foreach ([($row['uuid'] ?? null), ($row['id'] ?? null), ($self['uuid'] ?? null), ($self['id'] ?? null)] as $candidate) {
			if ((is_string($candidate) === true || is_int($candidate) === true) && (string)$candidate !== '') {
				return (string)$candidate;
			}
		}

		return '';
	}//end idOf()

	/**
	 * @param string            $value A date or date-time, '' for none.
	 * @param DateTimeImmutable $now   The moment.
	 *
	 * @return bool
	 */
	private function expired(string $value, DateTimeImmutable $now): bool {
		if ($value === '') {
			return false;
		}

		$when = date_create_immutable($value);

		return $when === false || $when <= $now;
	}//end expired()

	/**
	 * The last moment of a day in Amsterdam, as the date-time the register
	 * stores. An empty day stays empty: no end.
	 *
	 * @param string $day A day, `Y-m-d`, or ''.
	 *
	 * @return string
	 */
	private function endOfDay(string $day): string {
		if ($day === '') {
			return '';
		}

		$end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $day . ' 23:59:59', new DateTimeZone('Europe/Amsterdam'));
		if ($end === false) {
			return '';
		}

		return $end->format(DATE_ATOM);
	}//end endOfDay()

	/**
	 * @param string            $value A day, `Y-m-d`.
	 * @param DateTimeImmutable $now   The moment.
	 *
	 * @return bool Whether it is a real day after today.
	 */
	private function futureDay(string $value, DateTimeImmutable $now): bool {
		$day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($day === false || $day->format('Y-m-d') !== $value) {
			return false;
		}

		return $day > $now->setTime(23, 59, 59);
	}//end futureDay()
}//end class
