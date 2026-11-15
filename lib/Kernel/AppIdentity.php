<?php

/**
 * Portaliq app identity
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Structural app identity, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel;

/**
 * The app's identity, for every component that needs the app id.
 *
 * It lives in the kernel so naming the app id does not make a component
 * depend on the composition root in AppInfo.
 *
 * @spec exclude Structural app identity, no functional requirement.
 */
final class AppIdentity {

	/**
	 * The app id, as appinfo/info.xml declares it.
	 *
	 * @var string
	 */
	public const APP_ID = 'portaliq';
}//end class
