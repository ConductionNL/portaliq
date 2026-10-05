<?php

/**
 * Portaliq Waiting Account Secret
 *
 * Finds the waiting account an invitation's secret opens, and hashes a code
 * the way it is stored. A link's secret is stored as a plain SHA-256 (about
 * 248 bits); a code from a letter as an HMAC-SHA256 keyed from the
 * instance's own secret, because 60 bits could be tested offline against a
 * stolen plain hash (security review M4).
 *
 * Split out of WaitingAccountInvitation, which issues and redeems.
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
 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectReader;
use OCP\IConfig;

/**
 * The waiting account behind a secret, and the hash a code is stored under.
 *
 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
 */
class WaitingAccountSecret {
	/**
	 * The register the accounts live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The account schema.
	 */
	private const SCHEMA = 'portalAccount';

	/**
	 * The code's shape, normalisation and keyed hash.
	 *
	 * @var InvitationCode
	 */
	private readonly InvitationCode $codes;

	/**
	 * Constructor.
	 *
	 * @param PortalObjectReader $reader Finds the account a secret belongs to.
	 * @param IConfig $config Holds the instance secret a code's hash is keyed with.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
		private readonly IConfig $config,
	) {
		$this->codes = new InvitationCode();
	}//end __construct()

	/**
	 * Whether what was handed in is a code from a letter, however it was
	 * typed, rather than a link's secret.
	 *
	 * @param string $secret What was handed in.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function isCode(#[\SensitiveParameter] string $secret): bool {
		return $this->codes->normalise(typed: $secret) !== '';
	}//end isCode()

	/**
	 * The waiting account a secret opens in one organisation, or null.
	 *
	 * Unknown, spent, expired, another organisation's and an account that is
	 * no longer waiting all answer null.
	 *
	 * @param string $secret The secret.
	 * @param string $organisation The session's organisation.
	 * @param DateTimeImmutable $moment The moment to judge expiry against.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function find(#[\SensitiveParameter] string $secret, string $organisation, DateTimeImmutable $moment): ?array {
		if ($secret === '') {
			return null;
		}

		// A code is told from a link's secret by its shape: twelve characters
		// of the code alphabet, however the person typed them.
		$field = 'claimTokenHash';
		$hash  = hash('sha256', $secret);
		$code  = $this->codes->normalise(typed: $secret);
		if ($code !== '') {
			$field = 'claimCodeHash';
			$hash  = $this->codeHash(code: $code);
		}

		if ($hash === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: self::REGISTER,
			schema: self::SCHEMA,
			scopeField: $field,
			subjectRef: $hash,
			organisation: $organisation,
			limit: 5
		);

		foreach ($rows as $row) {
			// The reader's filter is trusted for the query, not for the
			// answer: every row is checked again.
			if (is_array($row) === false || $this->isWaitingRow(row: $row, field: $field, hash: $hash, organisation: $organisation) === false) {
				continue;
			}

			$expiry = date_create_immutable((string)($row['claimExpiresAt'] ?? ''));
			if ($expiry === false || $expiry <= $moment) {
				return null;
			}

			return $row;
		}

		return null;
	}//end find()

	/**
	 * The keyed hash a code is stored under, or '' when the instance has no
	 * secret to key it with (then no code is issued or accepted).
	 *
	 * @param string $code The plain code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
	 */
	public function codeHash(#[\SensitiveParameter] string $code): string {
		return $this->codes->keyedHash(code: $code, instanceSecret: $this->config->getSystemValueString('secret', ''));
	}//end codeHash()

	/**
	 * Whether a row is the waiting account a secret's hash opens: the hash
	 * matches, it sits in the organisation, it is pending and it has no
	 * identity reference of its own.
	 *
	 * @param array<string, mixed> $row One row the reader returned.
	 * @param string $field The field the hash is stored in.
	 * @param string $hash The secret's hash.
	 * @param string $organisation The session's organisation.
	 *
	 * @return bool
	 */
	private function isWaitingRow(array $row, string $field, string $hash, string $organisation): bool {
		return hash_equals((string)($row[$field] ?? ''), $hash) === true
			&& ($row['organisation'] ?? '') === $organisation
			&& ($row['status'] ?? '') === PortalAccountService::STATUS_PENDING
			&& (string)($row['identityRef'] ?? '') === '';
	}//end isWaitingRow()
}//end class
