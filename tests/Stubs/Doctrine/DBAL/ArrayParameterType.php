<?php

/**
 * Doctrine DBAL ArrayParameterType test double
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
 * The array parameter type constants of doctrine/dbal 3.10: the scalar type
 * plus DBAL's array offset of 100. Loaded only when DBAL itself is absent.
 */
final class ArrayParameterType {
	public const INTEGER = 101;
	public const STRING  = 102;
	public const ASCII   = 117;
	public const BINARY  = 116;
}//end class
