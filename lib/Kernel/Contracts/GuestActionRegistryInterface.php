<?php

/**
 * Contract of GuestActionRegistry
 *
 * The public surface of GuestActionRegistry that other components call, so they depend
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
 * Contract of GuestActionRegistry.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GuestActionRegistryInterface {

	/**
	 * The audience a provider declares to serve guests.
	 */
	public const GUEST_AUDIENCE = 'guest';

	/**
	 * One declared guest action, or null. Only an installed app whose
	 * provider serves the `guest` audience is asked, as a `low`-trust guest,
	 * and only an action marked `guest` that survived normalisation (which
	 * drops any guest action above `low`) is returned. Null covers an unknown
	 * app, an unknown action and a resident-only action alike, so the caller
	 * answers them with the same 404.
	 *
	 * @param string $appId The contributing app.
	 * @param string $actionId The declared action id.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T01
	 */
	public function guestAction(string $appId, string $actionId): ?array;
}//end interface
