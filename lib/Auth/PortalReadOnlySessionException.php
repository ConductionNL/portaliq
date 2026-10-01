<?php

/**
 * Portaliq Portal Read Only Session Exception
 *
 * Thrown by PortalAuthMiddleware when a protected portal request carries a
 * reference session. That session reads one case and nothing else
 * (identity-ways-in-screens D2), so every protected route refuses it with a
 * 403, and the controller method never runs.
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
 * @spec openspec/changes/archive/2026-10-01-identity-ways-in-screens/design.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Auth;

use Exception;

/**
 * Signals a reference session on a route it may not use.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-ways-in-screens/design.md
 */
class PortalReadOnlySessionException extends Exception {
}//end class
