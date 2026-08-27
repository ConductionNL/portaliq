<?php

/**
 * Portaliq Portal Unauthorized Exception
 *
 * Thrown by PortalAuthMiddleware when a protected portal request carries no
 * valid bearer session. Converted to a 401 JSON response so the guarded
 * controller method never runs without an authenticated subject (fail-closed).
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

use Exception;

/**
 * Signals a missing / invalid portal session on a protected route.
 *
 * @spec openspec/changes/supplier-portal/tasks.md#T02
 */
class PortalUnauthorizedException extends Exception {
}//end class
