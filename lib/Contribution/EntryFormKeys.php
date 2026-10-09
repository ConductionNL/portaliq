<?php

/**
 * Entry Form Keys
 *
 * The keys that let an action form ask for one entry of a day, the way a
 * student writes her placement hours (action-form-day-entry): a total the
 * form works out from two times and a pause, check cards for a list where
 * more than one answer fits, the words on a file button, a second button that
 * saves the entry for later, and a line under the buttons.
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
 * Keeps the day-entry keys of an action and its fields in shape.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
class EntryFormKeys {
	/**
	 * Check cards: more than one option may be chosen.
	 */
	public const CHECKS = 'checks';

	/**
	 * The one kind of computed value: hours between two times, minus a pause.
	 */
	public const DURATION = 'duration';

	/**
	 * The longest label, button text or line.
	 */
	private const MAX_TEXT = 200;

	/**
	 * The most check cards shown before "show all".
	 */
	private const MAX_SHOWN = 50;

	/**
	 * The field-level keys: `widget: checks` with `shown`, `inline` on choice
	 * cards, `computed`, and `buttonLabel` (the words on a file field's button).
	 *
	 * @param array<string, mixed> $entry  The sanitised entry built so far.
	 * @param array<string, mixed> $source The declared field config.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
	 */
	public function field(array $entry, array $source): array {
		if (($source['widget'] ?? null) === self::CHECKS) {
			$entry['widget'] = self::CHECKS;
			$shown = ($source['shown'] ?? null);
			if (is_int($shown) === true && $shown > 0 && $shown <= self::MAX_SHOWN) {
				$entry['shown'] = $shown;
			}
		}

		// Short choice cards side by side ("Geen", "30 min", "60 min").
		if (($source['widget'] ?? null) === FieldWidgetNormaliser::CHOICES && ($source['inline'] ?? null) === true) {
			$entry['inline'] = true;
		}

		$computed = $this->computed(value: ($source['computed'] ?? null));
		if ($computed !== null) {
			$entry['computed'] = $computed;
		}

		$button = $this->text(value: ($source['buttonLabel'] ?? null));
		if ($button !== null) {
			$entry['buttonLabel'] = $button;
		}

		return $entry;
	}//end field()

	/**
	 * Drop what does not fit once the action's fields and options are known:
	 * check cards need options, and a computed value needs its times (and its
	 * pause, when it names one) among the action's own fields, never itself.
	 *
	 * @param array<string, mixed> $action The action, after its options and input hints.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
	 */
	public function fit(array $action): array {
		$configs = ($action['fieldConfigs'] ?? null);
		if (is_array($configs) === false) {
			return $action;
		}

		$fields    = array_values(array_filter((array)($action['fields'] ?? []), 'is_string'));
		$providers = (array)($action['optionsProviders'] ?? []);
		foreach ($configs as $field => $config) {
			if (is_array($config) === false) {
				continue;
			}

			if (($config['widget'] ?? null) === self::CHECKS && isset($providers[$field]) === false) {
				unset($config['widget'], $config['shown']);
			}

			if (isset($config['computed']) === true && $this->computedFits(computed: $config['computed'], field: (string)$field, fields: $fields) === false) {
				unset($config['computed']);
			}

			$configs[$field] = $config;
		}

		$action['fieldConfigs'] = $configs;
		return $action;
	}//end fit()

	/**
	 * The action-level keys: `footnote` (a line under the buttons) and, on a
	 * create action, `saveForLater` (`{label, defaults}`: a second button that
	 * writes the entry with its own defaults, so it waits until it is sent).
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
	 */
	public function action(array $action): array {
		$footnote = $this->text(value: ($action['footnote'] ?? null));
		unset($action['footnote']);
		if ($footnote !== null) {
			$action['footnote'] = $footnote;
		}

		$later = $this->saveForLater(value: ($action['saveForLater'] ?? null));
		unset($action['saveForLater']);
		if ($later !== null && ($action['type'] ?? null) === 'create') {
			$action['saveForLater'] = $later;
		}

		return $action;
	}//end action()

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
			$computed = (is_array($config) === true) ? ($config['computed'] ?? null) : null;
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

	/**
	 * A well-formed `computed`: `{kind: duration, from, to, minus?}` with
	 * plain field names, or null.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<string, string>|null
	 */
	private function computed(mixed $value): ?array {
		if (is_array($value) === false || ($value['kind'] ?? null) !== self::DURATION) {
			return null;
		}

		$out = ['kind' => self::DURATION];
		foreach (['from', 'to', 'minus'] as $key) {
			$name = ($value[$key] ?? null);
			if (is_string($name) === true && preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $name) === 1) {
				$out[$key] = $name;
			}
		}

		if (isset($out['from'], $out['to']) === false) {
			return null;
		}

		return $out;
	}//end computed()

	/**
	 * Whether a computed value reads only other fields of the action.
	 *
	 * @param mixed              $computed The computed declaration.
	 * @param string             $field    The field it fills.
	 * @param array<int, string> $fields   The action's fields.
	 *
	 * @return bool
	 */
	private function computedFits(mixed $computed, string $field, array $fields): bool {
		$reads = array_values(array_intersect_key((array)$computed, ['from' => true, 'to' => true, 'minus' => true]));

		return in_array($field, $reads, true) === false && array_diff($reads, $fields) === [];
	}//end computedFits()

	/**
	 * A well-formed `saveForLater`: a label and a map of scalar defaults, or null.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array{label: string, defaults: array<string, mixed>}|null
	 */
	private function saveForLater(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		$label    = $this->text(value: ($value['label'] ?? null));
		$defaults = array_filter(
			(array)($value['defaults'] ?? []),
			static fn (mixed $default, mixed $key): bool => is_string($key) === true && $key !== '' && (is_scalar($default) === true || $default === null),
			ARRAY_FILTER_USE_BOTH
		);
		if ($label === null || $defaults === []) {
			return null;
		}

		return ['label' => $label, 'defaults' => $defaults];
	}//end saveForLater()

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
