<?php

/**
 * Doctrine DBAL ParameterType test double
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

namespace Doctrine\DBAL;

/**
 * The parameter type constants of doctrine/dbal 3.10 (the version Nextcloud
 * ships), loaded only when DBAL itself is absent, so OCP's IQueryBuilder
 * constants resolve in a run without a Nextcloud server.
 */
final class ParameterType {
	public const NULL         = 0;
	public const INTEGER      = 1;
	public const STRING       = 2;
	public const LARGE_OBJECT = 3;
	public const BOOLEAN      = 5;
	public const BINARY       = 16;
	public const ASCII        = 17;
}//end class
