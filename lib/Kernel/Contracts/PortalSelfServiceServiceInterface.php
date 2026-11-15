<?php

/**
 * Contract of PortalSelfServiceService
 *
 * The public surface of PortalSelfServiceService that other components call, so they depend
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
 * Contract of PortalSelfServiceService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSelfServiceServiceInterface {

	/**
	 * Confirm a new address through the link.
	 *
	 * A person who followed the mail holds the address. When an app
	 * provisioned a waiting account for that same address (a school inviting
	 * a guardian), its claims join the account that confirmed it, but only
	 * when the link was opened in that account's own session at trust
	 * substantial (confirmed-address-joins-the-waiting-account).
	 *
	 * @param string $token The secret from the confirmation mail.
	 * @param DateTimeImmutable|null $now The moment to judge expiry against.
	 * @param array<string, mixed>|null $session The session the link was
	 *                                           opened in, or null. Only the
	 *                                           account holder's own session
	 *                                           at trust substantial joins a
	 *                                           waiting account (review H1).
	 *
	 * @return array{email: string}|null Null when the link admits nobody:
	 *         unknown, already used or expired.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/specs/portal-identity-space/spec.md
	 */
	public function confirmEmail(string $token, ?DateTimeImmutable $now = null, ?array $session = null): ?array;

	/**
	 * The holder's own details, as the details page shows them.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return array{displayName: string, email: string, emailNotifications: bool, messageLanguage: string}|null
	 *         Null when there is no such account.
	 *
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function details(string $subjectRef): ?array;

	/**
	 * The language a holder reads school messages in, '' when they read them
	 * as written (translated-message-notice).
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return string The language tag, or ''.
	 *
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function messageLanguage(string $subjectRef): string;

	/**
	 * The bearer's own notification choices, with whether they registered a
	 * device for push. A missing choice reads as on.
	 *
	 * @param string $subjectRef The bearer's own subject reference.
	 *
	 * @return array{preferences: array<string, array<string, bool>>, pushAvailable: bool}|null Null without an account.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function notificationPreferences(string $subjectRef): ?array;

	/**
	 * Carry out the citizen's request to have the account removed.
	 *
	 * @param string $subjectRef The account.
	 *
	 * @return bool True when the account is emptied and marked removed.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 */
	public function removeAccount(string $subjectRef): bool;

	/**
	 * Change the details the citizen may change themselves.
	 *
	 * The display name lands at once. A new address does not: it is parked as
	 * `pendingEmail` with a one-time secret, and the answer carries that
	 * secret for the mail.
	 *
	 * @param string $subjectRef The account.
	 * @param string $displayName A new name, or '' to leave it.
	 * @param string $email A new address, or '' to leave it.
	 * @param bool|null $emailNotifications The account's own opt-in/opt-out
	 *                                      for the email channel
	 *                                      (notification-preferences-per-role),
	 *                                      or null to leave it unchanged.
	 * @param string|null $messageLanguage The language school messages are
	 *                                     shown in, '' to show them as
	 *                                     written, or null to leave it
	 *                                     (translated-message-notice).
	 *
	 * @return array{updated: bool, confirmationToken: string}|null Null when
	 *         there is no such account or nothing usable was asked.
	 *
	 * @spec openspec/specs/portal-identity-and-the-organisations-cases/spec.md
	 * @spec openspec/specs/supplier-portal/spec.md#requirement-an-accounts-own-channel-opt-out-gates-dispatch
	 * @spec openspec/specs/guardian-message-translation/spec.md#requirement-a-guardian-chooses-the-language-messages-are-shown-in
	 */
	public function updateDetails(
		string $subjectRef,
		string $displayName = '',
		string $email = '',
		?bool $emailNotifications = null,
		?string $messageLanguage = null,
	): ?array;

	/**
	 * Change the bearer's own notification choices. Only the known kinds and
	 * channels with a boolean value are taken; the rest of the body is ignored,
	 * and the account written is always the bearer's own.
	 *
	 * @param string               $subjectRef The bearer's own subject reference.
	 * @param array<string, mixed> $asked      The choices sent.
	 *
	 * @return array{preferences: array<string, array<string, bool>>, pushAvailable: bool}|null Null when refused.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function updateNotificationPreferences(string $subjectRef, array $asked): ?array;
}//end interface
