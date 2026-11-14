<?php

/**
 * Contract of PortalAccountActivationService
 *
 * The public surface of PortalAccountActivationService that other components call, so they depend
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
 * Contract of PortalAccountActivationService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalAccountActivationServiceInterface {

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
	public function activate(string $token, ?DateTimeImmutable $now = null): ?array;

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
	public function issue(string $subjectRef, ?DateTimeImmutable $now = null): ?string;
}//end interface
