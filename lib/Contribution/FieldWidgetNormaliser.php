<?php

/**
 * Portaliq Field Widget Normaliser (site-multi-step-forms, REQ-SMF-005)
 *
 * An action field MAY ask for a different way to answer it: `widget: choices`
 * draws a field with options as radio cards, and `widget: dateChoices` offers
 * a few named days before the date group. Both are presentation only; the
 * value sent and its validation stay the same.
 *
 * Fail-closed: an unknown widget is dropped and the field renders as its
 * default input. `choiceOptions` and `otherLabel` survive only with
 * `choices`, `dateChoices` (a count, 1 to 5, default 2) only with
 * `dateChoices`. Once the options and the input hint are known,
 * `reconcile()` drops a widget that does not fit its field and the
 * `choiceOptions` that are not among the field's static options.
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
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a known `widget` hint and its companions on a field config, or none.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
 */
class FieldWidgetNormaliser {
	/**
	 * Radio cards for a field with options.
	 */
	public const CHOICES = 'choices';

	/**
	 * Named days before the date group, for a date field.
	 */
	public const DATE_CHOICES = 'dateChoices';

	/**
	 * The most cards a `choiceOptions` subset may name.
	 */
	private const MAX_CHOICE_OPTIONS = 20;

	/**
	 * The longest value a `choiceOptions` entry may have.
	 */
	private const MAX_VALUE_LENGTH = 100;

	/**
	 * The longest `otherLabel`.
	 */
	private const MAX_LABEL_LENGTH = 200;

	/**
	 * Copy a known widget and its companions from a declared config.
	 *
	 * @param array<string, mixed> $entry The sanitised entry built so far.
	 * @param array<string, mixed> $source The declared field config.
	 *
	 * @return array<string, mixed> The entry, with the widget keys when they are valid.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
	 */
	public function apply(array $entry, array $source): array {
		$widget = ($source['widget'] ?? null);
		if ($widget === self::CHOICES) {
			return $this->withChoices(entry: $entry, source: $source);
		}

		if ($widget === self::DATE_CHOICES) {
			$entry['widget']      = self::DATE_CHOICES;
			$entry['dateChoices'] = $this->dayCount(value: ($source['dateChoices'] ?? null));
		}

		return $entry;
	}//end apply()

	/**
	 * Drop the widgets that do not fit their field, once the action's options
	 * and input hints are known: `choices` needs options, `dateChoices` a date
	 * input. A `choiceOptions` entry that is not one of the field's static
	 * options goes; with none left, `otherLabel` goes too.
	 *
	 * @param array<string, mixed> $action The action, after its options and input hints.
	 *
	 * @return array<string, mixed> The action.
	 *
	 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-an-action-field-may-ask-for-choice-cards-or-named-days-req-smf-005
	 */
	public function reconcile(array $action): array {
		$configs = ($action['fieldConfigs'] ?? null);
		if (is_array($configs) === false) {
			return $action;
		}

		$providers = ($action['optionsProviders'] ?? []);
		if (is_array($providers) === false) {
			$providers = [];
		}

		foreach ($configs as $field => $config) {
			if (is_array($config) === false || isset($config['widget']) === false) {
				continue;
			}

			$provider = ($providers[$field] ?? null);
			if ($config['widget'] === self::CHOICES) {
				$configs[$field] = $this->fitChoices(config: $config, provider: $provider);
				continue;
			}

			if (($config['input'] ?? null) !== 'date') {
				unset($config['widget'], $config['dateChoices']);
				$configs[$field] = $config;
			}
		}

		$action['fieldConfigs'] = $configs;
		return $action;
	}//end reconcile()

	/**
	 * The `choices` widget with its subset and its "other" card label.
	 *
	 * @param array<string, mixed> $entry The entry built so far.
	 * @param array<string, mixed> $source The declared field config.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function withChoices(array $entry, array $source): array {
		$entry['widget'] = self::CHOICES;
		$subset          = $this->choiceOptions(value: ($source['choiceOptions'] ?? null));
		if ($subset === []) {
			return $entry;
		}

		$entry['choiceOptions'] = $subset;
		$label = ($source['otherLabel'] ?? null);
		if (is_string($label) === true && trim($label) !== '' && mb_strlen($label) <= self::MAX_LABEL_LENGTH) {
			$entry['otherLabel'] = $label;
		}

		return $entry;
	}//end withChoices()

	/**
	 * Keep `choices` only on a field with options, and its subset only within
	 * the field's static options.
	 *
	 * @param array<string, mixed> $config The field config.
	 * @param mixed $provider The field's options provider, if any.
	 *
	 * @return array<string, mixed> The config.
	 */
	private function fitChoices(array $config, mixed $provider): array {
		if (is_array($provider) === false) {
			unset($config['widget'], $config['choiceOptions'], $config['otherLabel']);
			return $config;
		}

		if (isset($config['choiceOptions']) === false || ($provider['type'] ?? null) !== 'static') {
			return $config;
		}

		$known = [];
		foreach (($provider['options'] ?? []) as $option) {
			if (is_array($option) === true && isset($option['value']) === true) {
				$known[] = (string)$option['value'];
			}
		}

		$subset = array_values(array_filter($config['choiceOptions'], static fn (string $value): bool => in_array($value, $known, true)));
		if ($subset === []) {
			unset($config['choiceOptions'], $config['otherLabel']);
			return $config;
		}

		$config['choiceOptions'] = $subset;
		return $config;
	}//end fitChoices()

	/**
	 * A list of distinct option values, strings, capped.
	 *
	 * @param mixed $value The declared `choiceOptions`.
	 *
	 * @return array<int, string> The values, in declared order.
	 */
	private function choiceOptions(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === false && is_int($entry) === false) {
				continue;
			}

			$text = (string)$entry;
			if ($text === '' || mb_strlen($text) > self::MAX_VALUE_LENGTH || in_array($text, $out, true) === true) {
				continue;
			}

			$out[] = $text;
			if (count($out) === self::MAX_CHOICE_OPTIONS) {
				break;
			}
		}

		return $out;
	}//end choiceOptions()

	/**
	 * How many named days to offer: an integer 1 to 5, else 2.
	 *
	 * @param mixed $value The declared `dateChoices`.
	 *
	 * @return int The count.
	 */
	private function dayCount(mixed $value): int {
		if (is_int($value) === true && $value >= 1 && $value <= 5) {
			return $value;
		}

		return 2;
	}//end dayCount()
}//end class
