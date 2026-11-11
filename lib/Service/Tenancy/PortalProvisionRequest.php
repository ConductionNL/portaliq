<?php

/**
 * Portaliq Portal Provision Request
 *
 * The portal an organisation asks for, trimmed.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Tenancy
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
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Tenancy;

/**
 * The organisation, slug, title and host of a portal to provision, each
 * trimmed and the host in lower case.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */
final class PortalProvisionRequest {
	/**
	 * Constructor.
	 *
	 * @param string $organisation The organisation the portal belongs to.
	 * @param string $slug         The portal's slug.
	 * @param string $title        The portal's title.
	 * @param string $host         The host the portal answers on.
	 */
	public function __construct(
		public readonly string $organisation,
		public readonly string $slug,
		public readonly string $title,
		public readonly string $host,
	) {
	}//end __construct()
}//end class
