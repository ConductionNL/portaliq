<?php

/**
 * Nextcloud OC test double
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

/**
 * Nextcloud's `\OC` holder of the server container, loaded only when no
 * Nextcloud booted. OCP\Server::get() reads `\OC::$server`; the unit server
 * answers the services an OCP Response reads for its headers.
 */
class OC {
	/**
	 * The server container.
	 *
	 * @var \Psr\Container\ContainerInterface
	 */
	public static $server;
}//end class
