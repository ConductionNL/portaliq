<?php

/**
 * Doctrine DBAL Types test double
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

namespace Doctrine\DBAL\Types;

/**
 * The type names of doctrine/dbal 3.10 that OCP's IQueryBuilder reads.
 * Loaded only when DBAL itself is absent.
 */
final class Types {
	public const BOOLEAN              = 'boolean';
	public const DATE_MUTABLE         = 'date';
	public const DATE_IMMUTABLE       = 'date_immutable';
	public const DATETIME_MUTABLE     = 'datetime';
	public const DATETIME_IMMUTABLE   = 'datetime_immutable';
	public const DATETIMETZ_MUTABLE   = 'datetimetz';
	public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
	public const TIME_MUTABLE         = 'time';
}//end class
