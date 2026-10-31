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

use OCA\Portaliq\Service\PortalObjectReader;

/**
 * Finds the one account an e-mail link may sign in to.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#3
 */
class EmailLinkEligibility {
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
	 * @param PortalObjectReader $reader  Reads the accounts.
	 * @param EmailLinkAddress   $address Normalises the address.
	 */
	public function __construct(
		private readonly PortalObjectReader $reader,
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

		$rows = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portalAccount',
			scopeField: 'signInAddress',
			subjectRef: $normalised,
			organisation: $organisation,
			limit: self::LIMIT
		);

		$eligible = [];
		foreach ($rows as $row) {
			if (is_array($row) === true
				&& $this->address->normalise(address: (string)($row['signInAddress'] ?? '')) === $normalised
				&& $this->isEligible(account: $row, organisation: $organisation) === true
			) {
				$eligible[] = $row;
			}
		}

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
		if ($subjectRef === '' || $organisation === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portalAccount',
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			limit: 2
		);
		foreach ($rows as $row) {
			if (is_array($row) === true
				&& ($row['subjectRef'] ?? null) === $subjectRef
				&& $this->isEligible(account: $row, organisation: $organisation) === true
			) {
				return $row;
			}
		}

		return null;
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
		if (($account['identityType'] ?? '') !== self::IDENTITY_TYPE
			|| ($account['status'] ?? '') !== 'active'
			|| ($account['organisation'] ?? '') !== $organisation
			|| trim((string)($account['identityRef'] ?? '')) !== ''
			|| trim((string)($account['signInAddress'] ?? '')) === ''
		) {
			return false;
		}

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
	}//end isEligible()
}//end class
