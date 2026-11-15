<?php

/**
 * Contract of PortalContactService
 *
 * The public surface of PortalContactService that other components call, so they depend
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
 * Contract of PortalContactService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalContactServiceInterface {

	public const SENT = 'sent';

	public const INVALID = 'invalid';

	public const LIMIT = 'limit';

	public const DUPLICATE = 'duplicate';

	public const NOT_FOUND = 'not_found';

	/**
	 * A person who followed an invitation link and has an account now becomes
	 * the inviter's contact on both sides, without a second approval.
	 *
	 * @param array<string, mixed> $subject The new account's session subject.
	 * @param string $token The secret from the mail.
	 * @param string $displayName The new account's name.
	 * @param DateTimeImmutable|null $now The moment, for the expiry.
	 *
	 * @return string SENT when joined, else NOT_FOUND (also for an expired or used link).
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function acceptInvitation(array $subject, string $token, string $displayName, ?DateTimeImmutable $now=null): string;

	/**
	 * The address an open invitation went to, or ''.
	 *
	 * @param string $token The secret from the invitation mail.
	 * @param string $organisation The tenant of the portal being visited.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return string The address, or ''.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function invitationEmail(string $token, string $organisation, ?DateTimeImmutable $now=null): string;

	/**
	 * Invite someone by e-mail address.
	 *
	 * An address with an account gets an approval request, an address without
	 * one a mailed link valid for 14 days. The caller learns only that it was
	 * sent, so the answer does not say whether the address has an account.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $email The address.
	 * @param string $message An optional short message.
	 * @param DateTimeImmutable|null $now The moment, for the daily limit and the expiry.
	 *
	 * @return string One of SENT, INVALID, LIMIT, DUPLICATE or FAILED.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function invite(array $subject, string $email, string $message, ?DateTimeImmutable $now=null): string;

	/**
	 * The resident's contacts, and what waits for an answer.
	 *
	 * @param string $owner The resident's subject reference.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, mixed> `incoming`, `outgoing` and `contacts` lists of rows, and `counts` per role.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function overview(string $owner, string $organisation): array;

	/**
	 * Remove an approved contact: both rows end, earlier messages stay.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The row.
	 *
	 * @return string SENT when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function remove(array $subject, string $id): string;

	/**
	 * Send an invitation again with a new link and a new 14 days.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The invitation row.
	 * @param DateTimeImmutable|null $now The moment.
	 *
	 * @return string SENT, NOT_FOUND or FAILED.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function resend(array $subject, string $id, ?DateTimeImmutable $now=null): string;

	/**
	 * Answer a request from another account.
	 *
	 * Accepting sets both rows to `approved`; declining sets both to
	 * `declined`, which the requester reads only as "not accepted".
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The request row.
	 * @param bool $accept Whether to accept.
	 *
	 * @return string SENT when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function respond(array $subject, string $id, bool $accept): string;

	/**
	 * Take an invitation or request back.
	 *
	 * @param array<string, mixed> $subject The resident's session subject.
	 * @param string $id The row.
	 *
	 * @return string SENT when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t03
	 */
	public function withdraw(array $subject, string $id): string;

	/**
	 * The names a resident knows their contacts by, per reference, so a
	 * conversation shows the other person's name on each side.
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 *
	 * @return array<string, string> Name by subject reference.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function contactNames(string $owner, string $organisation): array;

	/**
	 * One approved contact of a resident by its row id, or null: the only
	 * people "Bericht sturen" may start a conversation with (REQ-ROC-001).
	 *
	 * @param string $owner The resident.
	 * @param string $organisation The tenant.
	 * @param string $id The resident's own contact row.
	 *
	 * @return array{id: string, ref: string, displayName: string}|null The contact.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t07
	 */
	public function approvedContact(string $owner, string $organisation, string $id): ?array;

	/**
	 * A visitor who registers through an invitation link: the open
	 * invitation is held for the new account.
	 *
	 * @param string $token The secret from the invitation mail.
	 * @param string $newRef The new, not yet active account.
	 * @param string $organisation The tenant.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return bool Whether an open invitation was held.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function holdForRegistration(string $token, string $newRef, string $organisation, ?DateTimeImmutable $now=null): bool;

	/**
	 * An account was activated: every invitation held for it joins both sides.
	 *
	 * @param array<string, mixed> $account The activated account: `subjectRef`, `organisation`, `displayName`.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return int How many invitations joined.
	 *
	 * @spec openspec/changes/own-contacts-and-invitations/tasks.md#t05
	 */
	public function joinOnActivation(array $account, ?DateTimeImmutable $now=null): int;
}//end interface
