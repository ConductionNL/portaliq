<?php

/**
 * Portaliq Schema Input Hint Normaliser (site-reaches-portal-parity, slice c)
 *
 * What the action's schema says a whitelisted field holds shapes its input on
 * the generic form: a `format: date` property becomes a date input, a number a
 * number input, and an `enum` a select. Without this a guardian types "the kind
 * of absence" into a free text box while the schema only accepts six values.
 *
 * The manifest wins: a field that already has an options provider keeps it, and
 * a file field is left alone. Only scalar enum values are offered, and only the
 * action's whitelisted fields are ever touched.
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
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Adds `input` hints, value types and enum options from the action's schema.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-form-must-send-each-value-in-the-type-its-field-declares
 */
class SchemaInputHintNormaliser {
	/**
	 * The most enum values offered as options. A longer list is not a choice
	 * a resident makes from a dropdown.
	 */
	private const MAX_OPTIONS = 100;

	/**
	 * The `input` hint per JSON-schema `format`.
	 */
	private const FORMAT_INPUTS = [
		'date'      => 'date',
		'date-time' => 'datetime',
		'email'     => 'email',
	];

	/**
	 * The `input` hint per JSON-schema `type`.
	 */
	private const TYPE_INPUTS = [
		'integer' => 'number',
		'number'  => 'number',
	];

	/**
	 * The JSON-schema types the site sends as something other than a string
	 * (site-action-forms): their `valueType`.
	 */
	private const VALUE_TYPES = ['integer', 'number', 'boolean'];

	/**
	 * Add the schema's hints to an action's field configs and options.
	 *
	 * @param array<string, mixed>      $action     The action, after its field configs and providers were normalised.
	 * @param array<int, string>        $whitelist  The action's whitelisted fields.
	 * @param array<string, mixed>|null $definition The action's schema definition, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-schema-form-must-render-only-whitelisted-fields-req-srp-022
	 */
	public function apply(array $action, array $whitelist, ?array $definition): array {
		$properties = ($definition['properties'] ?? null);
		if (is_array($properties) === false) {
			return $action;
		}

		foreach ($whitelist as $field) {
			$property = ($properties[$field] ?? null);
			if (is_array($property) === false || $this->isFileField(action: $action, field: $field) === true) {
				continue;
			}

			$action = $this->withInput(action: $action, field: $field, property: $property);
			$action = $this->withValueType(action: $action, field: $field, property: $property);
			$action = $this->withEnumOptions(action: $action, field: $field, property: $property);
		}

		return $action;
	}//end apply()

	/**
	 * Whether the manifest declared the field a file field.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param string               $field  The field.
	 *
	 * @return bool
	 */
	private function isFileField(array $action, string $field): bool {
		return (($action['fieldConfigs'][$field]['type'] ?? null) === FileFieldConfigNormaliser::TYPE_FILE);
	}//end isFileField()

	/**
	 * Set the field's `input` hint from its format or type.
	 *
	 * @param array<string, mixed> $action   The action.
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<string, mixed>
	 */
	private function withInput(array $action, string $field, array $property): array {
		$format = ($property['format'] ?? null);
		$type   = ($property['type'] ?? null);
		$input  = null;
		if (is_string($format) === true && isset(self::FORMAT_INPUTS[$format]) === true) {
			$input = self::FORMAT_INPUTS[$format];
		} else if (is_string($type) === true && isset(self::TYPE_INPUTS[$type]) === true) {
			$input = self::TYPE_INPUTS[$type];
		}

		if ($input === null) {
			return $action;
		}

		$configs = $this->configs(action: $action);
		$configs[$field] = array_merge(($configs[$field] ?? ['size' => 'medium']), ['input' => $input]);
		$action['fieldConfigs'] = $configs;
		return $action;
	}//end withInput()

	/**
	 * Set the field's `valueType` from its schema type, so the site sends an
	 * integer, a number or a boolean as one and not as a string. A string
	 * field gets none: the site sends text by default.
	 *
	 * @param array<string, mixed> $action   The action.
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-a-form-must-send-each-value-in-the-type-its-field-declares
	 */
	private function withValueType(array $action, string $field, array $property): array {
		$type = ($property['type'] ?? null);
		if (is_string($type) === false || in_array($type, self::VALUE_TYPES, true) === false) {
			return $action;
		}

		$configs = $this->configs(action: $action);
		$configs[$field] = array_merge(($configs[$field] ?? ['size' => 'medium']), ['valueType' => $type]);
		$action['fieldConfigs'] = $configs;
		return $action;
	}//end withValueType()

	/**
	 * Offer the property's enum as static options, unless the manifest already
	 * gave the field an options provider.
	 *
	 * @param array<string, mixed> $action   The action.
	 * @param string               $field    The field.
	 * @param array<string, mixed> $property The schema property.
	 *
	 * @return array<string, mixed>
	 */
	private function withEnumOptions(array $action, string $field, array $property): array {
		$providers = $action['optionsProviders'] ?? [];
		if (is_array($providers) === false || isset($providers[$field]) === true) {
			return $action;
		}

		$labels  = (new ValueLabelsNormaliser())->normalise(value: ($action['fieldConfigs'][$field][ValueLabelsNormaliser::KEY] ?? null));
		$options = $this->enumOptions(property: $property, labels: $labels);
		if ($options === []) {
			return $action;
		}

		$providers[$field] = ['type' => 'static', 'options' => $options];
		$action['optionsProviders'] = $providers;
		return $action;
	}//end withEnumOptions()

	/**
	 * The options of a property: its `oneOf` `const` and `title` pairs when it
	 * labels its values that way, else its `enum` with a readable label. A
	 * label the app declared in the field's `valueLabels` wins over both, so an
	 * app can put the options in the reader's language; the value submitted
	 * stays the raw one.
	 *
	 * @param array<string, mixed>  $property The schema property.
	 * @param array<string, string> $labels   The field's declared value labels.
	 *
	 * @return array<int, array{value: string, label: string}>
	 *
	 * @spec openspec/changes/contribution-value-labels/specs/portal-contribution-contract/spec.md#requirement-a-column-and-a-form-field-may-declare-how-their-values-read
	 */
	private function enumOptions(array $property, array $labels=[]): array {
		$options = [];
		foreach ($this->listOf(value: ($property['oneOf'] ?? null)) as $entry) {
			if (is_array($entry) === true && $this->isScalar(value: ($entry['const'] ?? null)) === true && is_string($entry['title'] ?? null) === true) {
				$value     = (string) $entry['const'];
				$options[] = ['value' => $value, 'label' => ($labels[$value] ?? $entry['title'])];
			}
		}

		if ($options === []) {
			foreach ($this->listOf(value: ($property['enum'] ?? null)) as $value) {
				if ($this->isScalar(value: $value) === true) {
					$options[] = ['value' => (string) $value, 'label' => ($labels[(string) $value] ?? $this->readable(value: (string) $value))];
				}
			}
		}

		return array_slice($options, 0, self::MAX_OPTIONS);
	}//end enumOptions()

	/**
	 * Whether a value can be an option's value.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isScalar(mixed $value): bool {
		return (is_string($value) === true && $value !== '') || is_int($value) === true;
	}//end isScalar()

	/**
	 * A slug value as words: `medical-appointment` reads "Medical appointment".
	 *
	 * @param string $value The enum value.
	 *
	 * @return string
	 */
	private function readable(string $value): string {
		return ucfirst(str_replace(['-', '_'], ' ', $value));
	}//end readable()

	/**
	 * The action's field configs, as an array.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private function configs(array $action): array {
		return $this->listOf(value: ($action['fieldConfigs'] ?? null));
	}//end configs()

	/**
	 * A value as an array, or an empty array when it is not one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end listOf()
}//end class
