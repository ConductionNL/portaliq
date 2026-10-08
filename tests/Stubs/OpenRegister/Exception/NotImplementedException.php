<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's class on
 * `development`, loaded only when OpenRegister is absent (tests/bootstrap.php)
 * and read by psalm and phpstan. Only the @spec tags differ: they point at
 * portaliq's spec, where OpenRegister's own changes do not exist.
 *
 * NotImplementedException — provider lacks a CRUD operation.
 *
 * Thrown by IntegrationProvider implementations whose storage
 * strategy doesn't support a particular operation. Query-time
 * providers (NC Activity, NC Shares) throw on create/update/delete;
 * list-only providers may also throw on get().
 *
 * The ObjectsController catches this and translates it to HTTP 501
 * Not Implemented (AD-22).
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Provider lacks a CRUD operation for its storage strategy.
 */
class NotImplementedException extends \RuntimeException {

}//end class
