<?php

/**
 * Contract of NotificationChannels
 *
 * The public surface of NotificationChannels that other components call, so they depend
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

/**
 * Contract of NotificationChannels.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface NotificationChannelsInterface {

	/**
	 * The e-mail channel.
	 *
	 * @var string
	 */
	public const CHANNEL_EMAIL = 'email';

	/**
	 * The push channel.
	 *
	 * @var string
	 */
	public const CHANNEL_PUSH = 'push';

	/**
	 * The record a change rule is about, from the job argument, or [].
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-notification-leads-to-the-record-req-nap-005
	 */
	public function record(array $argument): array;

	/**
	 * The kind of a notice: a notice about a record is a case change.
	 *
	 * @param array<string, string> $record The record, or [].
	 *
	 * @return string
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function kindOf(array $record): string;

	/**
	 * Whether the account wants this kind on this channel. A missing choice
	 * means on. Push is never wanted while the bound transport cannot put a
	 * push on a device: the attempt would be logged as sent for nothing.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param string               $kind    The kind.
	 * @param string               $channel The channel.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function wants(array $account, string $kind, string $channel): bool;

	/**
	 * Send a push to the resident's devices.
	 *
	 * @param string $subjectRef The resident.
	 * @param string $title      The title.
	 * @param string $body       The body.
	 *
	 * @return string|null Null when no device is registered (nothing to log),
	 *                     else `sent` (delivered, or queued for after quiet
	 *                     hours) or `failed`.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-the-resident-chooses-per-kind-and-per-channel-req-nap-007
	 */
	public function push(string $subjectRef, string $title, string $body): ?string;
}//end interface
