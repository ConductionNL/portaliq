<?php

/**
 * Portaliq Portal Name Setting
 *
 * The name residents know the portal's own area by ("Mijn Zuiddrecht"),
 * kept in the app config under `portalName` so other apps can name the
 * portal in their own words, for example a case's line about a message sent
 * to it. An administrator may set it; installing an example site fills it
 * from the site's `accountLabel` when nothing is set yet.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec openspec/specs/portal-name/spec.md#requirement-the-portal-has-a-name-other-apps-can-read
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and fills the portal's name in the app config.
 *
 * @spec openspec/specs/portal-name/spec.md#requirement-the-portal-has-a-name-other-apps-can-read
 */
class PortalNameSetting {
	/**
	 * The app-config key other apps read.
	 */
	public const KEY = 'portalName';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the name.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The portal's name, or '' when none is set.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/portal-name/spec.md#requirement-the-portal-has-a-name-other-apps-can-read
	 */
	public function name(): string {
		return trim($this->appConfig->getValueString(app: Application::APP_ID, key: self::KEY, default: ''));
	}//end name()

	/**
	 * Name the portal after a portal record's own area, unless a name is set.
	 *
	 * @param array<string, mixed> $portal A portal record or declaration.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-name/spec.md#requirement-an-installed-site-names-the-portal-when-none-is-set
	 */
	public function fillFrom(array $portal): void {
		$label = trim((string)($portal['accountLabel'] ?? ''));
		if ($label === '' || $this->name() !== '') {
			return;
		}

		$this->appConfig->setValueString(app: Application::APP_ID, key: self::KEY, value: $label);
	}//end fillFrom()
}//end class
