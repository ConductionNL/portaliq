<?php

/**
 * Portaliq Portal Protected marker
 *
 * Marker interface a controller implements to opt into PortalAuthMiddleware:
 * every method on such a controller requires a valid portal bearer session and
 * fails closed (401) otherwise. Public auth-edge endpoints (SessionController)
 * deliberately do NOT implement this — they must be reachable unauthenticated.
 *
 * @category Auth
 * @package  OCA\Portaliq\Auth
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
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Auth;

/**
 * Opt-in marker for controllers guarded by PortalAuthMiddleware.
 */
interface PortalProtected
{
}//end interface
