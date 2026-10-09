<?php

/**
 * Duration Field
 *
 * The hours between two times minus a pause, as an action's `computed`
 * field declares them (action-form-day-entry): worked out again on the server
 * from the times a create sends, so a typed total never stands.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Works a computed duration out.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
class DurationField {
	/**
	 * The body with every computed value worked out again on the server from
	 * the times it was sent with, so a typed total can never stand. A value
	 * whose times are missing or do not make a duration is left out.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param array<string, mixed> $body   The body to write.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
	 */
	public function stamp(array $action, array $body): array {
		foreach ((array)($action['fieldConfigs'] ?? []) as $field => $config) {
			$computed = ((array)$config)['computed'] ?? null;
			if (is_array($computed) === false) {
				continue;
			}

			unset($body[$field]);
			$hours = self::hours(
				from: $body[$computed['from']] ?? null,
				to: $body[$computed['to']] ?? null,
				pause: $body[($computed['minus'] ?? '')] ?? 0
			);
			if ($hours !== null) {
				$body[$field] = $hours;
			}
		}

		return $body;
	}//end stamp()

	/**
	 * The hours between two `HH:MM` times minus a pause in minutes, rounded
	 * to two decimals; null when a time is missing or malformed, or the
	 * result is not more than zero.
	 *
	 * @param mixed $from  The start time.
	 * @param mixed $to    The end time.
	 * @param mixed $pause The pause in minutes.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
	 */
	public static function hours(mixed $from, mixed $to, mixed $pause): ?float {
		$start = self::minutes(time: $from);
		$end   = self::minutes(time: $to);
		if ($start === null || $end === null || is_numeric($pause) === false) {
			return null;
		}

		$worked = ($end - $start - (int)$pause);
		if ($worked <= 0) {
			return null;
		}

		return round($worked / 60, 2);
	}//end hours()

	/**
	 * Minutes since midnight of an `HH:MM` (or `HH.MM`) time, or null.
	 *
	 * @param mixed $time The time.
	 *
	 * @return int|null
	 */
	private static function minutes(mixed $time): ?int {
		if (is_string($time) === false || preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', trim($time), $parts) !== 1) {
			return null;
		}

		return ((int)$parts[1] * 60) + (int)$parts[2];
	}//end minutes()
}//end class
