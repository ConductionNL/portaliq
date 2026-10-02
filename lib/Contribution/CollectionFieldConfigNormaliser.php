<?php

/**
 * Portaliq Collection Field Config Normaliser
 *
 * A collection may say how the values of any of its fields read, through
 * `fieldConfigs.<field>.valueLabels` (the same map a column carries) and
 * `fieldConfigs.<field>.label`. It is how a detail field that is no column
 * reads in words: "service" as "Dienstverlening". Presentation only, and
 * fail-closed: anything malformed is dropped, never thrown.
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
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a collection's well-formed `fieldConfigs`, or nothing.
 *
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read
 */
class CollectionFieldConfigNormaliser {
	/**
	 * The manifest key on a collection.
	 */
	public const KEY = 'fieldConfigs';

	/**
	 * The longest field label kept.
	 */
	private const MAX_LABEL_LENGTH = 200;

	/**
	 * Sanitise the collection's `fieldConfigs`: per field, a non-blank string
	 * `label` and a well-formed `valueLabels`, for a field the collection
	 * projects (or any field, when it projects none). The key goes when
	 * nothing usable is left.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-contribution-contract/spec.md#requirement-a-collection-may-say-how-the-values-of-any-of-its-fields-read
	 */
	public function normalise(array $collection): array {
		if (array_key_exists(self::KEY, $collection) === false) {
			return $collection;
		}

		$declared = $collection[self::KEY];
		unset($collection[self::KEY]);
		if (is_array($declared) === false) {
			return $collection;
		}

		$projected = ($collection['fields'] ?? null);
		$configs = [];
		foreach ($declared as $field => $config) {
			$entry = $this->fieldEntry(field: $field, config: $config, projected: $projected);
			if ($entry !== []) {
				$configs[$field] = $entry;
			}
		}

		if ($configs !== []) {
			$collection[self::KEY] = $configs;
		}

		return $collection;
	}//end normalise()

	/**
	 * The usable config of one declared field, or [] when the field is no
	 * name, its config no map, or it is a field the collection does not
	 * project.
	 *
	 * @param mixed $field     The declared field name.
	 * @param mixed $config    The declared config.
	 * @param mixed $projected The collection's projected `fields`, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function fieldEntry(mixed $field, mixed $config, mixed $projected): array {
		if (is_string($field) === false || $field === '' || is_array($config) === false) {
			return [];
		}

		if (is_array($projected) === true && in_array($field, $projected, true) === false) {
			return [];
		}

		return $this->entry(config: $config);
	}//end fieldEntry()

	/**
	 * The usable part of one field's config.
	 *
	 * @param array<mixed> $config The declared config.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(array $config): array {
		$entry = [];
		$label = ($config['label'] ?? null);
		if (is_string($label) === true && trim($label) !== '' && mb_strlen($label) <= self::MAX_LABEL_LENGTH) {
			$entry['label'] = $label;
		}

		return (new ValueLabelsNormaliser())->apply(entry: $entry, source: $config);
	}//end entry()
}//end class
