<?php

/**
 * Contract of PortalInvitationService
 *
 * The public surface of PortalInvitationService that other components call, so they depend
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
 * Contract of PortalInvitationService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalInvitationServiceInterface {

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
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function accept(string $token, ?DateTimeImmutable $now = null): ?array;

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
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function invite(string $email, string $organisation, string $audience, string $invitedBy, string $ttl = ''): ?array;

	/**
	 * Send an invitation that was not accepted again (REQ-ISA-011): a new
	 * secret replaces the old one, so the earlier link admits nobody, and the
	 * window starts again. A withdrawn or accepted invitation is not resent.
	 *
	 * @param string $id The invitation's id.
	 * @param string $organisation The tenant the clerk works for.
	 * @param string $ttl An ISO 8601 duration, or '' for the default week.
	 *
	 * @return array{token?: string, email?: string, expiresAt?: string, error?: string}
	 *         The new secret to mail with its address and expiry, or an `error`
	 *         of `not_found`, `already_accepted`, `withdrawn` or `not_saved`.
	 *
	 * @spec openspec/changes/staff-resend-an-invitation/specs/portal-account-administration/spec.md#requirement-staff-send-an-invitation-again-req-isa-011
	 */
	public function resend(string $id, string $organisation, string $ttl = ''): array;

	/**
	 * Withdraw an invitation that was not accepted (REQ-ISA-002). The link in
	 * the mail admits nobody afterwards. Withdrawing one twice changes
	 * nothing.
	 *
	 * @param string $id The invitation's id.
	 * @param string $organisation The tenant the clerk works for.
	 *
	 * @return string '' when it is withdrawn, else `not_found` or `already_accepted`.
	 *
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-see-and-withdraw-invitations-req-isa-002
	 */
	public function revoke(string $id, string $organisation): string;

	/**
	 * The invitations one sender has sent, with their state.
	 *
	 * @param string $invitedBy The sender.
	 * @param string $organisation The tenant.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 *
	 * @return array<int, array<string, mixed>> The invitations, state first.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function sentBy(string $invitedBy, string $organisation, ?DateTimeImmutable $now = null): array;
}//end interface
