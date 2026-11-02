<?php

/**
 * Portaliq Email Link Tokens
 *
 * The one-time token behind an e-mail link (security review L1, M2, M3, L4):
 * 48 characters from ISecureRandom over lower case and digits, stored only as
 * its SHA-256 with the account, the requesting browser's cookie hash, the
 * address hash and the expiry; compared with hash_equals; spent in one
 * conditional step under a lock; a newer link voids the older ones.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Identity\EmailLink
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCP\Security\ISecureRandom;

/**
 * Issues, reads, spends and voids e-mail link tokens.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#4
 */
class EmailLinkTokens {
	/**
	 * The spend went through: this caller signs in.
	 */
	public const SPENT = 'spent';

	/**
	 * The link was spent before.
	 */
	public const USED = 'used';

	/**
	 * Unknown, expired, voided by a newer link or by staff.
	 */
	public const NOT_VALID = 'not_valid';

	/**
	 * Opened without the request cookie and without the typed address.
	 */
	public const ADDRESS_NEEDED = 'address_needed';

	/**
	 * The typed address is not the one the link was sent to.
	 */
	public const ADDRESS_WRONG = 'address_wrong';

	/**
	 * Another redeem holds the lock.
	 */
	public const BUSY = 'busy';

	/**
	 * Token length; 48 characters over 36 symbols is about 248 bits.
	 */
	public const TOKEN_LENGTH = 48;

	/**
	 * How long a link works. Fixed: not configurable upward (L7).
	 */
	public const TTL = 'PT15M';

	/**
	 * The register the links live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The schema of a link.
	 */
	private const SCHEMA = 'portalEmailLink';

	/**
	 * How many unspent links of one account are read to void them.
	 */
	private const VOID_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Reads links by hash or account.
	 * @param PortalObjectWriter $writer Records, spends and voids links.
	 * @param ISecureRandom      $random Mints the token.
	 * @param ClaimLock          $lock   Serialises two redeems of one link.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
		private readonly ClaimLock $lock,
	) {
	}//end __construct()

	/**
	 * Issue a link for an eligible account, voiding its earlier unspent ones.
	 *
	 * @param array<string, mixed>   $account     The eligible account.
	 * @param string                 $portal      The portal slug it was asked on.
	 * @param string                 $cookieHash  SHA-256 of the requesting browser's cookie.
	 * @param string                 $addressHash SHA-256 of the normalised address.
	 * @param DateTimeImmutable|null $now         The moment of issue.
	 *
	 * @return string|null The plain token for the mail, or null when nothing was stored.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-token-is-strong-stored-as-a-hash-and-spent-once-req-iwi-009
	 */
	public function issue(array $account, string $portal, string $cookieHash, string $addressHash, ?DateTimeImmutable $now = null): ?string {
		$subjectRef   = (string)($account['subjectRef'] ?? '');
		$organisation = (string)($account['organisation'] ?? '');
		if ($subjectRef === '' || $organisation === '' || $addressHash === '') {
			return null;
		}

		$token = $this->random->generate(self::TOKEN_LENGTH, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		if (strlen($token) < self::TOKEN_LENGTH) {
			return null;
		}

		$this->voidFor(subjectRef: $subjectRef, organisation: $organisation);

		$moment  = ($now ?? new DateTimeImmutable());
		$created = $this->writer->createObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: $organisation,
			data: [
				'subjectRef'   => $subjectRef,
				'organisation' => $organisation,
				'portal'       => $portal,
				'tokenHash'    => hash('sha256', $token),
				'cookieHash'   => $cookieHash,
				'addressHash'  => $addressHash,
				'state'        => 'sent',
				'issuedAt'     => $moment->format(DATE_ATOM),
				'expiresAt'    => $moment->add(new DateInterval(self::TTL))->format(DATE_ATOM),
			]
		);
		if ($created === null) {
			return null;
		}

		return $token;
	}//end issue()

	/**
	 * What a link is, without spending it: the row and one of SPENT's
	 * siblings (`live` when it can still be spent).
	 *
	 * @param string                 $token The token from the fragment.
	 * @param DateTimeImmutable|null $now   The moment to judge expiry against.
	 *
	 * @return array{state: string, row: array<string, mixed>|null}
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-link-opened-in-another-browser-asks-for-the-address-first-req-iwi-010
	 */
	public function peek(string $token, ?DateTimeImmutable $now = null): array {
		$row = $this->rowFor(token: $token);
		return ['state' => $this->stateOf(row: $row, moment: ($now ?? new DateTimeImmutable())), 'row' => $row];
	}//end peek()

	/**
	 * Spend a link in one conditional step: lock, read again, check the
	 * browser or the typed address, spend, unlock. A wrong or missing proof
	 * spends nothing.
	 *
	 * @param string                 $token       The token from the fragment.
	 * @param string                 $cookieHash  SHA-256 of this browser's cookie, or ''.
	 * @param string                 $addressHash SHA-256 of the typed address, or ''.
	 * @param DateTimeImmutable|null $now         The moment of the redeem.
	 *
	 * @return array{outcome: string, row: array<string, mixed>|null}
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-token-is-strong-stored-as-a-hash-and-spent-once-req-iwi-009
	 */
	public function spend(string $token, string $cookieHash, string $addressHash, ?DateTimeImmutable $now = null): array {
		if ($token === '') {
			return ['outcome' => self::NOT_VALID, 'row' => null];
		}

		$key = 'email-link/' . hash('sha256', $token);
		if ($this->lock->acquire(accountId: $key) === false) {
			return ['outcome' => self::BUSY, 'row' => null];
		}

		try {
			return $this->spendLocked(token: $token, cookieHash: $cookieHash, addressHash: $addressHash, moment: ($now ?? new DateTimeImmutable()));
		} finally {
			$this->lock->release(accountId: $key);
		}
	}//end spend()

	/**
	 * Void every unspent link of an account: a newer link, or staff (L4).
	 *
	 * @param string $subjectRef   The account's subject reference.
	 * @param string $organisation The account's organisation.
	 *
	 * @return int How many links were voided.
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-staff-can-revoke-an-accounts-e-mail-links-and-sessions-req-iwi-013
	 */
	public function voidFor(string $subjectRef, string $organisation): int {
		if ($subjectRef === '') {
			return 0;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			limit: self::VOID_LIMIT,
			filter: ['state' => 'sent']
		);

		$voided = 0;
		foreach ($rows as $row) {
			$id = $this->identifierOf(row: (array)$row);
			if ($id === '' || ($row['subjectRef'] ?? null) !== $subjectRef || ($row['state'] ?? '') !== 'sent') {
				continue;
			}

			$updated = $this->writer->updateObject(
				register: self::REGISTER,
				schema: self::SCHEMA,
				scopeField: '',
				subjectRef: '',
				organisation: '',
				id: $id,
				data: ['state' => 'void']
			);
			if ($updated !== null) {
				$voided++;
			}
		}

		return $voided;
	}//end voidFor()

	/**
	 * The spend itself, under the lock.
	 *
	 * @param string            $token       The token.
	 * @param string            $cookieHash  This browser's cookie hash, or ''.
	 * @param string            $addressHash The typed address hash, or ''.
	 * @param DateTimeImmutable $moment      The moment of the redeem.
	 *
	 * @return array{outcome: string, row: array<string, mixed>|null}
	 */
	private function spendLocked(string $token, string $cookieHash, string $addressHash, DateTimeImmutable $moment): array {
		// Read again under the lock: the read before the lock is what let two
		// opens at once both sign in on the reference link (M2).
		$row   = $this->rowFor(token: $token);
		$state = $this->stateOf(row: $row, moment: $moment);
		if ($state !== 'live' || $row === null) {
			return ['outcome' => $state, 'row' => null];
		}

		$proof = $this->proofOutcome(row: $row, cookieHash: $cookieHash, addressHash: $addressHash);
		if ($proof !== null) {
			return ['outcome' => $proof, 'row' => null];
		}

		$spent = $this->writer->updateObject(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: '',
			subjectRef: '',
			organisation: '',
			id: $this->identifierOf(row: $row),
			data: ['state' => 'used', 'usedAt' => $moment->format(DATE_ATOM)]
		);
		if ($spent === null) {
			// A spend that did not land signs nobody in.
			return ['outcome' => self::NOT_VALID, 'row' => null];
		}

		return ['outcome' => self::SPENT, 'row' => $row];
	}//end spendLocked()

	/**
	 * Null when this browser or the typed address proves the link, else why not.
	 *
	 * @param array<string, mixed> $row         The live link.
	 * @param string               $cookieHash  This browser's cookie hash, or ''.
	 * @param string               $addressHash The typed address hash, or ''.
	 *
	 * @return string|null
	 */
	private function proofOutcome(array $row, string $cookieHash, string $addressHash): ?string {
		$storedCookie = (string)($row['cookieHash'] ?? '');
		if ($storedCookie !== '' && $cookieHash !== '' && hash_equals($storedCookie, $cookieHash) === true) {
			return null;
		}

		if ($addressHash === '') {
			return self::ADDRESS_NEEDED;
		}

		if (hash_equals((string)($row['addressHash'] ?? ''), $addressHash) === false) {
			return self::ADDRESS_WRONG;
		}

		return null;
	}//end proofOutcome()

	/**
	 * The stored row behind a token, or null.
	 *
	 * @param string $token The token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function rowFor(string $token): ?array {
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
			limit: 2
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && hash_equals((string)($row['tokenHash'] ?? ''), $hash) === true) {
				return $row;
			}
		}

		return null;
	}//end rowFor()

	/**
	 * `live`, `used` or `not_valid` for a row at a moment.
	 *
	 * @param array<string, mixed>|null $row    The row, or null.
	 * @param DateTimeImmutable         $moment The moment.
	 *
	 * @return string
	 */
	private function stateOf(?array $row, DateTimeImmutable $moment): string {
		if ($row === null) {
			return self::NOT_VALID;
		}

		$state = (string)($row['state'] ?? '');
		if ($state === 'used') {
			return self::USED;
		}

		$expiry = date_create_immutable((string)($row['expiresAt'] ?? ''));
		if ($state !== 'sent' || $expiry === false || $expiry <= $moment) {
			return self::NOT_VALID;
		}

		return 'live';
	}//end stateOf()

	/**
	 * The stored identifier of a row, wherever OpenRegister put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 */
	private function identifierOf(array $row): string {
		$id = (string)($row['uuid'] ?? $row['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		$self = (array)($row['@self'] ?? []);

		return (string)($self['uuid'] ?? $self['id'] ?? '');
	}//end identifierOf()
}//end class
