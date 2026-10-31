<?php

/**
 * Portaliq Collection Schema Labels (collection-column-labels)
 *
 * A collection table without declared `columns` showed its field keys as
 * headers, humanised but in the app's own (English) words: "Course name",
 * "Method block", "Component id". The schema already says what each field
 * is called: its property `title`. This fills `fieldConfigs.<field>.label`
 * from that title for every projected field the app did not label, so the
 * site reads a name the app wrote rather than a key.
 *
 * The manifest wins: a label in `fieldConfigs` or on a declared column is
 * never replaced. A schema that cannot be read changes nothing.
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
 * @spec openspec/changes/collection-column-labels/specs/portal-contribution-contract/spec.md#requirement-a-collection-field-must-read-under-its-schema-title-when-the-app-gave-no-label
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

use OCA\Portaliq\Service\PortalSchemaReader;

/**
 * Labels a collection's projected fields with their schema property titles.
 *
 * @spec openspec/changes/collection-column-labels/specs/portal-contribution-contract/spec.md#requirement-a-collection-field-must-read-under-its-schema-title-when-the-app-gave-no-label
 */
class CollectionSchemaLabels {
	/**
	 * The longest title kept, as for a declared field label.
	 */
	private const MAX_LABEL_LENGTH = 200;

	/**
	 * The schema properties read so far, per slug, so one manifest asks the
	 * register once per schema.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $properties = [];

	/**
	 * Constructor.
	 *
	 * @param PortalSchemaReader|null $schemaReader Reads a schema by slug; null changes nothing.
	 */
	public function __construct(
		private readonly ?PortalSchemaReader $schemaReader = null,
	) {
	}//end __construct()

	/**
	 * Fill the missing field labels of every collection from its schema.
	 *
	 * @param array<int, array<string, mixed>> $collections The normalised collections.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/collection-column-labels/specs/portal-contribution-contract/spec.md#requirement-a-collection-field-must-read-under-its-schema-title-when-the-app-gave-no-label
	 */
	public function apply(array $collections): array {
		if ($this->schemaReader === null) {
			return $collections;
		}

		return array_map(fn (array $collection): array => $this->labelled(collection: $collection), $collections);
	}//end apply()

	/**
	 * One collection with its missing field labels filled.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 */
	private function labelled(array $collection): array {
		$properties = $this->propertiesOf(slug: ($collection['schema'] ?? null));
		if ($properties === []) {
			return $collection;
		}

		$configs = (array)($collection[CollectionFieldConfigNormaliser::KEY] ?? []);
		foreach ($this->fieldsOf(collection: $collection) as $field) {
			if (isset($configs[$field]['label']) === true) {
				continue;
			}

			$title = $this->titleOf(property: ($properties[$field] ?? null));
			if ($title !== null) {
				$configs[$field] = array_merge((array)($configs[$field] ?? []), ['label' => $title]);
			}
		}

		if ($configs !== []) {
			$collection[CollectionFieldConfigNormaliser::KEY] = $configs;
		}

		return $collection;
	}//end labelled()

	/**
	 * The fields a collection shows: its projected `fields`, else its columns'.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, string>
	 */
	private function fieldsOf(array $collection): array {
		$fields = ($collection['fields'] ?? null);
		if (is_array($fields) === false) {
			$fields = [];
			foreach ((array)($collection['columns'] ?? []) as $column) {
				if (is_array($column) === true) {
					$fields[] = ($column['field'] ?? null);
				}
			}
		}

		return array_values(array_filter($fields, static fn ($field): bool => is_string($field) === true && $field !== ''));
	}//end fieldsOf()

	/**
	 * A schema's properties by slug, read once; [] when it cannot be read.
	 *
	 * @param mixed $slug The collection's schema slug.
	 *
	 * @return array<string, mixed>
	 */
	private function propertiesOf(mixed $slug): array {
		if (is_string($slug) === false || $slug === '' || $this->schemaReader === null) {
			return [];
		}

		if (array_key_exists($slug, $this->properties) === false) {
			$definition = $this->schemaReader->readSchema(slug: $slug);
			$this->properties[$slug] = (array)($definition['properties'] ?? []);
		}

		return $this->properties[$slug];
	}//end propertiesOf()

	/**
	 * A property's usable title, or null.
	 *
	 * @param mixed $property The schema property.
	 *
	 * @return string|null
	 */
	private function titleOf(mixed $property): ?string {
		if (is_array($property) === false) {
			return null;
		}

		$title = ($property['title'] ?? null);
		if (is_string($title) === false) {
			return null;
		}

		$title = trim($title);
		if ($title === '' || mb_strlen($title) > self::MAX_LABEL_LENGTH) {
			return null;
		}

		return $title;
	}//end titleOf()
}//end class
