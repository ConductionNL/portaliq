<?php

/**
 * Contract of PortalProvisioningService
 *
 * The public surface of PortalProvisioningService that other components call, so they depend
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
 * Contract of PortalProvisioningService.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalProvisioningServiceInterface {

	public const CREATED = 'created';

	public const INVALID = 'invalid';

	public const SLUG_TAKEN = 'slug_taken';

	public const HOST_TAKEN = 'host_taken';

	public const UNAVAILABLE = 'unavailable';

	public const FAILED = 'failed';

	/**
	 * Create the draft portal of an organisation.
	 *
	 * Refuses a slug that any portal holds, and a host that any portal lists,
	 * whichever organisation holds it, before anything is written.
	 *
	 * @param string $organisation The organisation the portal belongs to.
	 * @param string $slug The portal's slug.
	 * @param string $title The portal's title.
	 * @param string $host The host the portal answers on, without scheme or port.
	 *
	 * @return array{status: string, dns: array{name: string, value: string}|null} The outcome and, when created, the DNS record to publish.
	 *
	 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
	 */
	public function provision(string $organisation, string $slug, string $title, string $host): array;
}//end interface
