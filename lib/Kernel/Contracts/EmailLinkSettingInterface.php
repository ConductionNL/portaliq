<?php

/**
 * Contract of EmailLinkSetting
 *
 * The public surface of EmailLinkSetting that other components call, so they depend
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
 * Contract of EmailLinkSetting.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailLinkSettingInterface {

	/**
	 * The settings key the admin screen reads and writes.
	 */
	public const SETTINGS_KEY = 'email_link_signin_enabled';

	/**
	 * The sign-in mode a portal declares in `authentication.modes`.
	 */
	public const MODE = 'email-link';

	/**
	 * Whether an administrator turned the e-mail link sign-in on.
	 *
	 * Anything but the stored value `1` is off, so a missing or garbled
	 * value never opens the door.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
	 */
	public function isEnabled(): bool;

	/**
	 * Whether a portal offers the e-mail link: the switch is on AND the portal
	 * declares the mode. A portal that does not declare it never offers it.
	 *
	 * @param array<string, mixed>|null $portal The portal row.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/specs/portal-ways-in/spec.md#requirement-a-portal-may-let-an-existing-e-mail-account-sign-in-with-a-one-time-e-mail-link-req-iwi-006
	 */
	public function offeredBy(?array $portal): bool;

	/**
	 * Turn the e-mail link sign-in on or off.
	 *
	 * @param bool $enabled The new state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
	 */
	public function setEnabled(bool $enabled): void;
}//end interface
