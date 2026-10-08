<?php

/**
 * Portaliq Timetable Keys (calendar-timetable-display)
 *
 * A `calendar` block MAY draw one day as a timetable: `display: timetable`,
 * each item a row with its start and end time, the gaps between items as
 * breaks, a changed item with a pill and a cancelled item struck through.
 * With `range: week` the block also offers the days of the week as tiles.
 *
 * Block keys: `display: timetable` and `firstLabel` (the small label over the
 * first item of the day, authored text). Source keys, next to `startField`,
 * `endField`, `titleField` and `metaField`: `noteField` (a third line),
 * `statusField` (the field whose word, from the collection's value labels, is
 * the pill) and `cancelledWhen` (`{field, in}`: the rows that are cancelled).
 *
 * A key that does not fit is dropped; the block keeps its older shape.
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
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises the timetable keys of a calendar block and of its sources.
 *
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
class TimetableKeys {
	/**
	 * The longest first-item label.
	 */
	private const MAX_LABEL = 60;

	/**
	 * The most values a `cancelledWhen` rule lists.
	 */
	private const MAX_VALUES = 10;

	/**
	 * The block keys of a timetable: the display and its optional first label.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
	 */
	public function blockKeys(array $block): array {
		if (($block['display'] ?? null) !== 'timetable') {
			return [];
		}

		$out   = ['display' => 'timetable'];
		$label = ($block['firstLabel'] ?? null);
		if (is_string($label) === true && trim($label) !== '' && mb_strlen(trim($label)) <= self::MAX_LABEL) {
			$out['firstLabel'] = trim($label);
		}

		return $out;
	}//end blockKeys()

	/**
	 * The timetable keys of one source (or of its `expand` list).
	 *
	 * @param array<string, mixed> $declared The declared source or expansion.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
	 */
	public function sourceKeys(array $declared): array {
		$out = [];
		foreach (['noteField', 'statusField'] as $key) {
			if ($this->isName(value: ($declared[$key] ?? null)) === true) {
				$out[$key] = $declared[$key];
			}
		}

		$cancelled = $this->rule(declared: ($declared['cancelledWhen'] ?? null));
		if ($cancelled !== null) {
			$out['cancelledWhen'] = $cancelled;
		}

		return $out;
	}//end sourceKeys()

	/**
	 * A `{field, in}` rule, or null when it names no field or no value.
	 *
	 * @param mixed $declared The declared rule.
	 *
	 * @return array{field: string, in: array<int, string>}|null
	 */
	private function rule(mixed $declared): ?array {
		if (is_array($declared) === false || $this->isName(value: ($declared['field'] ?? null)) === false) {
			return null;
		}

		$values = array_values(array_filter((array)($declared['in'] ?? []), fn ($value): bool => $this->isName(value: $value)));
		if ($values === []) {
			return null;
		}

		return ['field' => $declared['field'], 'in' => array_slice($values, 0, self::MAX_VALUES)];
	}//end rule()

	/**
	 * Whether a value is a non-empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isName(mixed $value): bool {
		return is_string($value) === true && $value !== '';
	}//end isName()
}//end class
