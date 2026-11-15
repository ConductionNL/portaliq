<?php

/**
 * Portaliq Email Link Eligibility
 *
 * Which account an e-mail link may reach (security review H1, L6): identity
 * type `email`, `active`, the portal's organisation, no identity reference and
 * no claims a broker set, and exactly one such account behind the address.
 * Everything else answers null, and the caller answers it exactly like an
 * unknown address.
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

use OCA\Portaliq\Kernel\Contracts\EmailLinkEligibilityInterface;
use OCA\Portaliq\Service\ScopedCollectionReaderInterface;

/**
 * Finds the one account an e-mail link may sign in to.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#3
 */
class EmailLinkEligibility implements EmailLinkEligibilityInterface {
	/**
	 * The identity type of an account that signs in with an e-mail link.
	 */
	public const IDENTITY_TYPE = 'email';

	/**
	 * Claim keys only a broker login writes. An app's own claim (a learniq
	 * participant, a pipelinq contact) is not one of them and does not block.
	 */
	private const BROKER_CLAIM_KEYS = ['digid', 'eherkenning', 'eidas', 'broker', 'oidc'];

	/**
	 * How many rows are read behind one address: enough to see that there is
	 * more than one.
	 */
	private const LIMIT = 5;

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader  Reads the accounts.
	 * @param EmailLinkAddress   $address Normalises the address.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
		private readonly EmailLinkAddress $address,
	) {
	}//end __construct()

	/**
	 * The one eligible account whose sign-in address this is, or null.
	 *
	 * @param string $address      The address as typed.
	 * @param string $organisation The portal's organisation.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-portal-may-let-an-existing-e-mail-account-sign-in-with-a-one-time-e-mail-link-req-iwi-006
	 */
	public function accountFor(string $address, string $organisation): ?array {
		$normalised = $this->address->normalise(address: $address);
		if ($normalised === '' || $organisation === '') {
			return null;
		}

		$eligible = $this->eligibleAt(normalised: $normalised, organisation: $organisation);

		// Shared by more than one account: nobody gets a link (L6).
		if (count($eligible) !== 1) {
			return null;
		}

		return $eligible[0];
	}//end accountFor()

	/**
	 * The account behind a subject reference, when it is still eligible: the
	 * check redeem makes again, so an account withdrawn after the link was
	 * mailed does not sign in (L4).
	 *
	 * @param string $subjectRef   The account's subject reference.
	 * @param string $organisation The link's organisation.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-the-e-mail-link-token-is-strong-stored-as-a-hash-and-spent-once-req-iwi-009
	 */
	public function stillEligible(string $subjectRef, string $organisation): ?array {
		if (in_array('', [$subjectRef, $organisation], true) === true) {
			return null;
		}

		return $this->firstEligible(rows: $this->ownRows(subjectRef: $subjectRef, organisation: $organisation), organisation: $organisation);
	}//end stillEligible()

	/**
	 * Whether one account may be reached by an e-mail link.
	 *
	 * @param array<string, mixed> $account      The account row.
	 * @param string               $organisation The portal's organisation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-portal-may-let-an-existing-e-mail-account-sign-in-with-a-one-time-e-mail-link-req-iwi-006
	 */
	public function isEligible(array $account, string $organisation): bool {
		return $this->isEmailAccountOf(account: $account, organisation: $organisation) === true && $this->holdsNoBrokerClaim(account: $account) === true;
	}//end isEligible()

	/**
	 * The eligible accounts whose sign-in address this is, in the order read.
	 *
	 * @param string $normalised   The normalised address.
	 * @param string $organisation The portal's organisation.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function eligibleAt(string $normalised, string $organisation): array {
		$query    = ['scopeField' => 'signInAddress', 'subjectRef' => $normalised, 'organisation' => $organisation, 'limit' => self::LIMIT];
		$rows     = $this->accounts(query: $query);
		$eligible = [];
		foreach ($rows as $row) {
			if ($this->hasAddress(row: $row, normalised: $normalised) === true
				&& $this->isEligible(account: $row, organisation: $organisation) === true
			) {
				$eligible[] = $row;
			}
		}

		return $eligible;
	}//end eligibleAt()

	/**
	 * The portal accounts the reader answers for a scope.
	 *
	 * @param array{scopeField: string, subjectRef: string, organisation: string, limit: int} $query The scope.
	 *
	 * @return array<mixed>
	 */
	private function accounts(array $query): array {
		return $this->reader->readCollection(...(['register' => 'portaliq', 'schema' => 'portalAccount'] + $query));
	}//end accounts()

	/**
	 * Whether a row is an account with this sign-in address, once normalised.
	 *
	 * @param mixed  $row        The row.
	 * @param string $normalised The normalised address.
	 *
	 * @return bool
	 */
	private function hasAddress(mixed $row, string $normalised): bool {
		return is_array($row) === true && $this->address->normalise(address: (string)($row['signInAddress'] ?? '')) === $normalised;
	}//end hasAddress()

	/**
	 * The accounts the reader answers for a subject reference that carry exactly that reference.
	 *
	 * @param string $subjectRef   The account's subject reference.
	 * @param string $organisation The link's organisation.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function ownRows(string $subjectRef, string $organisation): array {
		$query = ['scopeField' => 'subjectRef', 'subjectRef' => $subjectRef, 'organisation' => $organisation, 'limit' => 2];
		$rows  = $this->accounts(query: $query);
		$own   = [];
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['subjectRef'] ?? null) === $subjectRef) {
				$own[] = $row;
			}
		}

		return $own;
	}//end ownRows()

	/**
	 * The first eligible account, or null.
	 *
	 * @param list<array<string, mixed>> $rows         The accounts.
	 * @param string                     $organisation The organisation.
	 *
	 * @return array<string, mixed>|null
	 */
	private function firstEligible(array $rows, string $organisation): ?array {
		foreach ($rows as $row) {
			if ($this->isEligible(account: $row, organisation: $organisation) === true) {
				return $row;
			}
		}

		return null;
	}//end firstEligible()

	/**
	 * Whether an account is an active e-mail account of the organisation with
	 * a sign-in address and no identity reference.
	 *
	 * @param array<string, mixed> $account      The account row.
	 * @param string               $organisation The portal's organisation.
	 *
	 * @return bool
	 */
	private function isEmailAccountOf(array $account, string $organisation): bool {
		return $this->isActiveEmailAccount(account: $account) === true
			&& ($account['organisation'] ?? '') === $organisation
			&& $this->hasAddressOnly(account: $account) === true;
	}//end isEmailAccountOf()

	/**
	 * Whether an account is an e-mail account that is active.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return bool
	 */
	private function isActiveEmailAccount(array $account): bool {
		return ($account['identityType'] ?? '') === self::IDENTITY_TYPE && ($account['status'] ?? '') === 'active';
	}//end isActiveEmailAccount()

	/**
	 * Whether an account has a sign-in address and no identity reference.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return bool
	 */
	private function hasAddressOnly(array $account): bool {
		return trim((string)($account['identityRef'] ?? '')) === '' && trim((string)($account['signInAddress'] ?? '')) !== '';
	}//end hasAddressOnly()

	/**
	 * Whether an account's claims hold no broker identity.
	 *
	 * @param array<string, mixed> $account The account row.
	 *
	 * @return bool
	 */
	private function holdsNoBrokerClaim(array $account): bool {
		$claims = ($account['claims'] ?? []);
		if (is_array($claims) === false) {
			return false;
		}

		foreach (self::BROKER_CLAIM_KEYS as $key) {
			if (empty($claims[$key]) === false) {
				return false;
			}
		}

		return true;
	}//end holdsNoBrokerClaim()
}//end class
