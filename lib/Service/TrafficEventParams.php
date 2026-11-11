<?php

/**
 * Portaliq Traffic Event Params.
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
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Bounds a posted event's `params` map, for TrafficEventValidator only.
 *
 * @internal
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
 */
final class TrafficEventParams {
	/**
	 * The bounded `params` map: at most MAX_PARAMS scalar entries with short
	 * keys and short values. Everything else is dropped, never refused.
	 *
	 * @param mixed $value The posted map.
	 *
	 * @return array<string, string|int|float|bool> The bounded map.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-a-portal-must-decide-what-is-measured-and-the-collector-must-enforce-it
	 */
	public function bounded(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $key => $item) {
			if (count($out) >= TrafficEventValidator::MAX_PARAMS) {
				break;
			}

			if (is_string($key) === false || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key) !== 1) {
				continue;
			}

			$scalar = $this->scalar(item: $item);
			if ($scalar !== null) {
				$out[$key] = $scalar;
			}
		}

		return $out;
	}//end bounded()

	/**
	 * A param value as stored: a bounded string, a number or a boolean. Null
	 * for anything else, which the caller drops.
	 *
	 * @param mixed $item The posted value.
	 *
	 * @return string|int|float|bool|null The storable value, or null.
	 */
	private function scalar(mixed $item): string|int|float|bool|null {
		if (is_string($item) === true) {
			return mb_substr($item, 0, TrafficEventValidator::MAX_PARAM_VALUE);
		}

		if (is_int($item) === true || is_float($item) === true || is_bool($item) === true) {
			return $item;
		}

		return null;
	}//end scalar()
}//end class
