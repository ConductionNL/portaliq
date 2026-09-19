<?php

/**
 * Portaliq Portal Invitation Service
 *
 * Inviting an address into the portal, for the person who has no national
 * login and no account: the desk sends a link, the sender can see what
 * happened to it, and the link stops admitting anybody after its expiry.
 *
 * The secret is in the mail and nowhere else. What is stored is its SHA-256,
 * so a reader of the register cannot walk in on somebody else's invitation,
 * and lookup is by hash, never by the secret itself.
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
 * Issues, tracks and redeems portal invitations.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalInvitationService {
	/**
	 * The register the invitation lives in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema recording an invitation.
	 */
	private const SCHEMA = 'portalInvitation';

	/**
	 * How long an invitation admits anybody, by default.
	 */
	private const DEFAULT_TTL = 'P7D';

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Looks the invitation up by hash.
	 * @param PortalObjectWriter $writer Records and updates the invitation.
	 * @param PortalAccountService $accounts Provisions the account on acceptance.
	 * @param ISecureRandom $random Mints the one-time secret.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly PortalAccountService $accounts,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Invite an address, and hand the caller the secret to mail.
	 *
	 * @param string $email The address invited.
	 * @param string $organisation The tenant inviting.
	 * @param string $audience The audience the account will carry.
	 * @param string $invitedBy The staff user sending it.
	 * @param string $ttl An ISO 8601 duration, or '' for the default week.
	 *
	 * @return array{token: string, expiresAt: string}|null Null when refused.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function invite(string $email, string $organisation, string $audience, string $invitedBy, string $ttl = ''): ?array {
		if ($email === '' || $organisation === '' || $invitedBy === '') {
			return null;
		}

		$token = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		if ($token === '') {
			return null;
		}

		$now = new DateTimeImmutable();

		$window = self::DEFAULT_TTL;
		if ($ttl !== '') {
			$window = $ttl;
		}

		$expiry = $now->add(new DateInterval($window));

		$forAudience = 'client';
		if ($audience !== '') {
			$forAudience = $audience;
		}

		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'email' => $email,
				'organisation' => $organisation,
				'audience' => $forAudience,
				'tokenHash' => hash('sha256', $token),
				'state' => 'sent',
				'invitedBy' => $invitedBy,
				'sentAt' => $now->format(DATE_ATOM),
				'expiresAt' => $expiry->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		return ['token' => $token, 'expiresAt' => $expiry->format(DATE_ATOM)];
	}//end invite()

	/**
	 * The invitations one sender has sent, with their state.
	 *
	 * @param string $invitedBy The sender.
	 * @param string $organisation The tenant.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array<int, array<string, mixed>> The invitations, state first.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function sentBy(string $invitedBy, string $organisation, ?DateTimeImmutable $now = null): array {
		if ($invitedBy === '') {
			return [];
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'invitedBy',
			subjectRef: $invitedBy,
			organisation: $organisation,
			limit: 200
		);

		$moment = ($now ?? new DateTimeImmutable());
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === false || ($row['invitedBy'] ?? '') !== $invitedBy) {
				continue;
			}

			$out[] = [
				'email' => (string)($row['email'] ?? ''),
				// The state the sender sees is the state as of now: an
				// invitation nobody has touched since it lapsed still says
				// sent on the row, and saying that back would be a lie.
				'state' => $this->stateAsOf(row: $row, now: $moment),
				'sentAt' => (string)($row['sentAt'] ?? ''),
				'expiresAt' => (string)($row['expiresAt'] ?? ''),
			];
		}

		return $out;
	}//end sentBy()

	/**
	 * Follow an invitation: mark it opened, or refuse it.
	 *
	 * @param string $token The secret from the mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{state: string, email: string}|null Null when no invitation
	 *         matches; otherwise the state, which may be `expired`.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function open(string $token, ?DateTimeImmutable $now = null): ?array {
		$row = $this->findByToken(token: $token);
		if ($row === null) {
			return null;
		}

		$state = $this->stateAsOf(row: $row, now: ($now ?? new DateTimeImmutable()));
		if ($state !== 'sent') {
			return ['state' => $state, 'email' => (string)($row['email'] ?? '')];
		}

		$this->markState(row: $row, state: 'opened');

		return ['state' => 'opened', 'email' => (string)($row['email'] ?? '')];
	}//end open()

	/**
	 * Accept an invitation: the account is provisioned for the address and the
	 * invitation is spent.
	 *
	 * @param string $token The secret from the mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{subjectRef: string}|null Null when the invitation admits
	 *         nobody: unknown, expired, revoked or already accepted.
	 *
	 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function accept(string $token, ?DateTimeImmutable $now = null): ?array {
		$row = $this->findByToken(token: $token);
		if ($row === null) {
			return null;
		}

		$state = $this->stateAsOf(row: $row, now: ($now ?? new DateTimeImmutable()));
		if ($state !== 'sent' && $state !== 'opened') {
			return null;
		}

		$account = $this->accounts->provision(
			audience: (string)($row['audience'] ?? 'client'),
			organisation: (string)($row['organisation'] ?? ''),
			email: (string)($row['email'] ?? ''),
			verifiedEmail: true,
			provisionedBy: (string)($row['invitedBy'] ?? '')
		);
		if ($account === null) {
			return null;
		}

		$this->markState(row: $row, state: 'accepted', extra: ['subjectRef' => $account['subjectRef']]);

		return ['subjectRef' => $account['subjectRef']];
	}//end accept()

	/**
	 * The invitation carrying this secret's hash, or null.
	 *
	 * @param string $token The secret.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findByToken(string $token): ?array {
		if ($token === '') {
			return null;
		}

		$hash = hash('sha256', $token);
		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'tokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);

		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['tokenHash'] ?? ''), $hash) === true) {
				return $row;
			}
		}

		return null;
	}//end findByToken()

	/**
	 * The state of an invitation as of a moment, expiry included.
	 *
	 * @param array<string, mixed> $row The invitation row.
	 * @param DateTimeImmutable $now The moment.
	 *
	 * @return string
	 */
	private function stateAsOf(array $row, DateTimeImmutable $now): string {
		$state = (string)($row['state'] ?? 'sent');
		if ($state === 'accepted' || $state === 'revoked' || $state === 'expired') {
			return $state;
		}

		$expiresAt = (string)($row['expiresAt'] ?? '');
		if ($expiresAt === '') {
			return $state;
		}

		$expiry = date_create_immutable($expiresAt);
		if ($expiry === false || $expiry <= $now) {
			return 'expired';
		}

		return $state;
	}//end stateAsOf()

	/**
	 * Write a new state on the invitation row.
	 *
	 * @param array<string, mixed> $row The invitation row.
	 * @param string $state The new state.
	 * @param array<string, mixed> $extra Anything else to stamp.
	 *
	 * @return void
	 */
	private function markState(array $row, string $state, array $extra = []): void {
		$id = (string)($row['uuid'] ?? $row['id'] ?? '');
		if ($id === '') {
			$self = (array)($row['@self'] ?? []);
			$id = (string)($self['uuid'] ?? $self['id'] ?? '');
		}

		if ($id === '') {
			return;
		}

		$this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $id,
			data: array_merge(['state' => $state], $extra)
		);
	}//end markState()
}//end class
