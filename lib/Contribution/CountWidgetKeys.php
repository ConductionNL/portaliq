<?php

/**
 * Portaliq Count Widget Keys (count-field)
 *
 * The companions of `widget: count`: `min` and `max` (whole numbers, 0 to
 * 999, `max` at least `min`), `unit` (the unit's two forms, `{one, other}`)
 * and `priceLabel` (the price per unit as authored text, for the line
 * "3 deelnemers × [PRIJS]"). A companion that does not fit is dropped; the
 * widget stays with the defaults 1 and 99.
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
 * @spec openspec/changes/count-field/specs/portal-contribution-contract/spec.md#requirement-a-field-may-declare-a-count-stepper
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps the count widget and its companions in shape.
 *
 * @spec openspec/changes/count-field/specs/portal-contribution-contract/spec.md#requirement-a-field-may-declare-a-count-stepper
 */
class CountWidgetKeys {
	/**
	 * The longest unit word or price label.
	 */
	private const MAX_TEXT = 60;

	/**
	 * The entry with `widget: count` and its companions that fit.
	 *
	 * @param array<string, mixed> $entry    The sanitised entry built so far.
	 * @param array<string, mixed> $source   The declared field config.
	 * @param int                  $maxCount The highest count allowed.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/count-field/specs/portal-contribution-contract/spec.md#requirement-a-field-may-declare-a-count-stepper
	 */
	public function apply(array $entry, array $source, int $maxCount): array {
		$entry['widget'] = FieldWidgetNormaliser::COUNT;
		$min = $this->whole(value: ($source['min'] ?? null), maxCount: $maxCount);
		$max = $this->whole(value: ($source['max'] ?? null), maxCount: $maxCount);
		if ($min !== null) {
			$entry['min'] = $min;
		}

		if ($max !== null && $max >= ($min ?? 1)) {
			$entry['max'] = $max;
		}

		$unit = $this->unit(value: ($source['unit'] ?? null));
		if ($unit !== null) {
			$entry['unit'] = $unit;
		}

		$price = $this->text(value: ($source['priceLabel'] ?? null));
		if ($price !== null) {
			$entry['priceLabel'] = $price;
		}

		return $entry;
	}//end apply()

	/**
	 * Drop the count where it does not fit: a field with options, a date or a file.
	 *
	 * @param array<string, mixed> $config   The field config, after the input hints.
	 * @param mixed                $provider The field's options provider, if any.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/count-field/specs/portal-contribution-contract/spec.md#requirement-a-field-may-declare-a-count-stepper
	 */
	public function fit(array $config, mixed $provider): array {
		$isDate = in_array(($config['input'] ?? null), ['date', 'datetime'], true);
		if ($provider === null && $isDate === false && ($config['type'] ?? null) !== 'file') {
			return $config;
		}

		unset($config['widget'], $config['min'], $config['max'], $config['unit'], $config['priceLabel']);

		return $config;
	}//end fit()

	/**
	 * A whole number 0 to the maximum, or null.
	 *
	 * @param mixed $value    The declared value.
	 * @param int   $maxCount The highest count allowed.
	 *
	 * @return int|null
	 */
	private function whole(mixed $value, int $maxCount): ?int {
		if (is_int($value) === false || $value < 0 || $value > $maxCount) {
			return null;
		}

		return $value;
	}//end whole()

	/**
	 * `{one, other}` with both forms as short text, or null.
	 *
	 * @param mixed $value The declared unit.
	 *
	 * @return array{one: string, other: string}|null
	 */
	private function unit(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$one = $this->text(value: ($value['one'] ?? null));
		$other = $this->text(value: ($value['other'] ?? null));
		if ($one === null || $other === null) {
			return null;
		}

		return ['one' => $one, 'other' => $other];
	}//end unit()

	/**
	 * A short, non-empty text, or null.
	 *
	 * @param mixed $value The declared text.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '' || mb_strlen($value) > self::MAX_TEXT) {
			return null;
		}

		return trim($value);
	}//end text()
}//end class
