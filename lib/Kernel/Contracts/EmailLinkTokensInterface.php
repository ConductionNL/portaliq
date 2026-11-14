<?php

/**
 * Contract of EmailLinkTokens
 *
 * The public surface of EmailLinkTokens that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of EmailLinkTokens.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailLinkTokensInterface {

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
	public function peek(string $token, ?DateTimeImmutable $now = null): array;

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
	public function spend(string $token, string $cookieHash, string $addressHash, ?DateTimeImmutable $now = null): array;

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
	public function voidFor(string $subjectRef, string $organisation): int;
}//end interface
