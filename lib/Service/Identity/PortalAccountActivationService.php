<?php

/**
 * Portaliq Portal Account Activation Service
 *
 * The activation link of a self-registration under the `activation` policy
 * (identity-ways-in-screens D4). The account is provisioned `pending`; this
 * mints the one-time secret that goes out by mail, keeps only its SHA-256 on
 * the account, and makes the account active with a verified address when the
 * link is followed.
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
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateInterval;
use DateTimeImmutable;
use OCA\Portaliq\Kernel\Contracts\PortalAccountActivationServiceInterface;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;
use OCP\Security\ISecureRandom;

/**
 * Issues and spends the activation link of a self-registered account.
 *
 * @spec openspec/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
class PortalAccountActivationService implements PortalAccountActivationServiceInterface {

	/**
	 * How long an activation link works.
	 */
	public const TTL = 'P2D';

	/**
	 * The register the accounts live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Finds the account a secret belongs to.
	 * @param PortalObjectWriter $writer Stores the hash and the activation.
	 * @param ISecureRandom $random Mints the secret.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly PortalObjectWriter $writer,
		private readonly ISecureRandom $random,
	) {
	}//end __construct()

	/**
	 * Mint the activation secret for a pending self-registered account.
	 *
	 * Only the hash is stored. Asking again replaces the earlier link, so a
	 * lost mail is answered by registering once more.
	 *
	 * @param string $subjectRef The account.
	 * @param DateTimeImmutable|null $now The moment the link is dated from.
	 *
	 * @return string|null The plain secret for the mail, or null when the
	 *                     account is not a pending self-registration or the
	 *                     write failed.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T01
	 */
	public function issue(string $subjectRef, ?DateTimeImmutable $now = null): ?string {
		$uuid = $this->pendingUuid(subjectRef: $subjectRef);
		if ($uuid === null) {
			return null;
		}

		$token   = $this->random->generate(48, (ISecureRandom::CHAR_LOWER . ISecureRandom::CHAR_DIGITS));
		$written = $this->storeToken(uuid: $uuid, token: $token, now: $now);
		if ($written === false) {
			return null;
		}

		return $token;
	}//end issue()

	/**
	 * The uuid of a pending self-registered account, or null.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T01
	 */
	private function pendingUuid(string $subjectRef): string|null {
		$lookup  = new PortalAccountLookup(reader: $this->reader);
		$account = $lookup->bySubjectRef(subjectRef: $subjectRef);
		if ($account === null || $this->awaitsActivation(account: $account) === false) {
			return null;
		}

		return $lookup->identifierOf(row: $account);
	}//end pendingUuid()

	/**
	 * Store the secret's hash and its expiry on the account.
	 *
	 * @param string                 $uuid  The account's uuid.
	 * @param string                 $token The plain secret.
	 * @param DateTimeImmutable|null $now   The moment the link is dated from.
	 *
	 * @return bool Whether the write succeeded.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T01
	 */
	private function storeToken(string $uuid, string $token, DateTimeImmutable|null $now): bool {
		return $this->write(
			id: $uuid,
			data: [
				'activationTokenHash' => hash('sha256', $token),
				'activationExpiresAt' => ($now ?? new DateTimeImmutable())->add(new DateInterval(self::TTL))->format(DATE_ATOM),
			]
		);
	}//end storeToken()

	/**
	 * Follow an activation link: the account becomes active, its address
	 * verified, and the link is spent.
	 *
	 * Unknown, spent, expired and an account that is no longer pending all
	 * answer null: the caller holds a working link or they do not.
	 *
	 * @param string $token The secret from the mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array{organisation: string, subjectRef: string, displayName: string}|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T03
	 */
	public function activate(string $token, ?DateTimeImmutable $now = null): ?array {
		$account = $this->liveAccount(token: $token, now: $now);
		if ($account === null) {
			return null;
		}

		if ($this->activateAccount(account: $account) === false) {
			return null;
		}

		return [
			'organisation' => (string)($account['organisation'] ?? ''),
			'subjectRef' => (string)($account['subjectRef'] ?? ''),
			'displayName' => (string)($account['displayName'] ?? ''),
		];
	}//end activate()

	/**
	 * The pending account a non-empty secret opens, or null.
	 *
	 * @param string                 $token The secret from the mail.
	 * @param DateTimeImmutable|null $now   The moment to judge expiry against.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T03
	 */
	private function liveAccount(string $token, DateTimeImmutable|null $now): array|null {
		if ($token === '') {
			return null;
		}

		return $this->accountFor(hash: hash('sha256', $token), moment: ($now ?? new DateTimeImmutable()));
	}//end liveAccount()

	/**
	 * Make the account active with its address verified, and spend the link.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return bool Whether the write succeeded.
	 *
	 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T03
	 */
	private function activateAccount(array $account): bool {
		$uuid = (new PortalAccountLookup(reader: $this->reader))->identifierOf(row: $account);
		if ($uuid === null) {
			return false;
		}

		return $this->write(
			id: $uuid,
			data: [
				'status' => PortalAccountService::STATUS_ACTIVE,
				'verifiedEmail' => true,
				// The hash goes, so the link works once. The expiry stays as
				// it was: an empty string is no date-time.
				'activationTokenHash' => '',
			]
		);
	}//end activateAccount()

	/**
	 * Whether an account is one an activation link may open.
	 *
	 * Staff-provisioned and invited accounts never are: they are activated by
	 * the sign-in that matches them, not by a mailed link.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return bool
	 */
	private function awaitsActivation(array $account): bool {
		return ($account['status'] ?? '') === PortalAccountService::STATUS_PENDING
			&& ($account['provisionedBy'] ?? '') === PortalAccountService::SELF_REGISTRATION;
	}//end awaitsActivation()

	/**
	 * The pending account a link's hash opens, or null.
	 *
	 * @param string $hash The secret's hash.
	 * @param DateTimeImmutable $moment The moment to judge expiry against.
	 *
	 * @return array<string, mixed>|null
	 */
	private function accountFor(string $hash, DateTimeImmutable $moment): ?array {
		foreach ($this->candidates(hash: $hash) as $row) {
			// The reader's scope filter is trusted for the query, not for the
			// answer: every row is checked against the hash again.
			if ($this->holdsHash(row: $row, hash: $hash) === false) {
				continue;
			}

			if ($this->isOpenable(row: $row, moment: $moment) === false) {
				return null;
			}

			return $row;
		}

		return null;
	}//end accountFor()

	/**
	 * The rows the reader finds for the hash.
	 *
	 * @param string $hash The secret's hash.
	 *
	 * @return array
	 */
	private function candidates(string $hash): array {
		return $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: 'activationTokenHash',
			subjectRef: $hash,
			organisation: '',
			limit: 5
		);
	}//end candidates()

	/**
	 * Whether a row is an account holding exactly this hash.
	 *
	 * @param mixed  $row  The row.
	 * @param string $hash The secret's hash.
	 *
	 * @return bool
	 */
	private function holdsHash(mixed $row, string $hash): bool {
		if (is_array($row) === false || hash_equals((string)($row['activationTokenHash'] ?? ''), $hash) === false) {
			return false;
		}

		return true;
	}//end holdsHash()

	/**
	 * Whether the account's link is unexpired and the account still pending.
	 *
	 * @param array<string, mixed> $row    The account row.
	 * @param DateTimeImmutable    $moment The moment to judge expiry against.
	 *
	 * @return bool
	 */
	private function isOpenable(array $row, DateTimeImmutable $moment): bool {
		$expiry = date_create_immutable((string)($row['activationExpiresAt'] ?? ''));
		if ($expiry === false || $expiry <= $moment || $this->awaitsActivation(account: $row) === false) {
			return false;
		}

		return true;
	}//end isOpenable()

	/**
	 * Write fields onto the account.
	 *
	 * @param string $id The account's uuid.
	 * @param array<string, mixed> $data The fields.
	 *
	 * @return bool
	 */
	private function write(string $id, array $data): bool {
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
