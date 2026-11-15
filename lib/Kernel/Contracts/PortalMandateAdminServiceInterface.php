<?php

/**
 * Contract of PortalMandateAdminService
 *
 * The public surface of PortalMandateAdminService that other components call, so they depend
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
 * Contract of PortalMandateAdminService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalMandateAdminServiceInterface {

	public const BAD_END_DATE = 'bad_end_date';

	public const OWN_INVITATION = 'own_invitation';

	public const GONE = 'gone';

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
	public function accept(string $token, array $subject, ?DateTimeImmutable $now = null): array|string;

	/**
	 * Every mandate and open invitation given on this party's behalf.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return array<int, array<string, mixed>> `{kind, id, label, caseTypes, expiresAt, state, email|holder}`;
	 *         a mandate also carries the `name` and `email` of its account.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-the-represented-party-must-see-who-may-act-for-it-req-smr-002
	 */
	public function given(string $party, string $organisation, ?DateTimeImmutable $now = null): array;

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
	public function held(array $holders, string $organisation, ?DateTimeImmutable $now = null): array;

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
	public function invite(string $party, string $organisation, string $invitedBy, array $terms, ?DateTimeImmutable $now = null): array|string;

	/**
	 * Revoke a mandate given on the party's behalf.
	 *
	 * @param string                 $party        The session's party.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $actor           Who ends it (a subject reference).
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function revoke(string $party, string $organisation, string $id, string $actor, ?DateTimeImmutable $now = null): string;

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
	public function revokeInvitation(string $party, string $organisation, string $id): string;

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
	public function setExpiry(string $party, string $organisation, string $id, string $day, ?DateTimeImmutable $now = null): string;

	/**
	 * The holder stops its own mandate.
	 *
	 * @param array<int, string>     $holders      The parties the session carries.
	 * @param string                 $organisation The tenant.
	 * @param string                 $id           The mandate.
	 * @param string                 $actor           Who stops it.
	 * @param DateTimeImmutable|null $now          The moment.
	 *
	 * @return string '' when done, else NOT_FOUND.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/specs/portal-mandates/spec.md#requirement-either-side-must-be-able-to-end-a-mandate-req-smr-004
	 */
	public function stop(array $holders, string $organisation, string $id, string $actor, ?DateTimeImmutable $now = null): string;
}//end interface
