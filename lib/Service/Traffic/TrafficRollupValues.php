<?php

/**
 * Portaliq Traffic Rollup Values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The number readings a roll-up sum shares: a non-negative integer or
 * float from a stored value, and a ratio.
 *
 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
 */
final class TrafficRollupValues {

	/**
	 * A ratio, or 0 when there is nothing to divide by.
	 *
	 * @param float $numerator   The top.
	 * @param int   $denominator The bottom.
	 *
	 * @return float The ratio.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function ratio(float $numerator, int $denominator): float {
		if ($denominator <= 0) {
			return 0.0;
		}

		return $numerator / $denominator;
	}

	/**
	 * A non-negative integer.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int The integer.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function int(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return max(0, (int)$value);
	}
	/**
	 * A non-negative float.
	 *
	 * @param mixed $value The value.
	 *
	 * @return float The float.
	 *
	 * @spec openspec/changes/portal-traffic-reporting/specs/portal-traffic-reporting/spec.md#requirement-a-roll-up-portal-must-sum-its-members-and-never-count-its-own
	 */
	public function float(mixed $value): float {
		if (is_numeric($value) === false) {
			return 0.0;
		}

		return max(0.0, (float)$value);
	}
}
