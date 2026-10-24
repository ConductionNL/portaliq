<?php

/**
 * Portaliq Email Link Setting
 *
 * The instance switch in front of the e-mail link sign-in (decision 127):
 * OFF by default, and the security review comes before an administrator
 * turns it on. While it is off no portal offers or accepts the mode, even a
 * portal that declares it.
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
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Identity\EmailLink;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and writes the e-mail link switch, and says whether a portal offers the mode.
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 */
class EmailLinkSetting {
	/**
	 * The app config key of the switch.
	 */
	public const CONFIG_KEY = 'email_link_signin';

	/**
	 * The settings key the admin screen reads and writes.
	 */
	public const SETTINGS_KEY = 'email_link_signin_enabled';

	/**
	 * The sign-in mode a portal declares in `authentication.modes`.
	 */
	public const MODE = 'email-link';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

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
	public function isEnabled(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '0') === '1';
	}//end isEnabled()

	/**
	 * Turn the e-mail link sign-in on or off.
	 *
	 * @param bool $enabled The new state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
	 */
	public function setEnabled(bool $enabled): void {
		$value = '0';
		if ($enabled === true) {
			$value = '1';
		}

		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, $value);
	}//end setEnabled()

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
	public function offeredBy(?array $portal): bool {
		if ($portal === null || $this->isEnabled() === false) {
			return false;
		}

		$modes = (((array)($portal['authentication'] ?? []))['modes'] ?? []);

		return is_array($modes) === true && in_array(self::MODE, $modes, true) === true;
	}//end offeredBy()
}//end class
