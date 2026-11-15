<?php

/**
 * Portaliq Collection Projection (site-mijn-omgeving-components)
 *
 * Which fields a contribution's collection projects, for the blocks that name
 * fields of their collection. A collection that projects no fields allows
 * every field.
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
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * The fields of a collection a block may name.
 *
 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class CollectionProjection {

	/**
	 * Whether the collection projects this field (any field, when it projects none).
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param string               $field      The field.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function projects(array $collection, string $field): bool {
		if ($field === '') {
			return false;
		}

		$fields = ($collection['fields'] ?? null);
		return is_array($fields) === false || in_array($field, $fields, true) === true;
	}//end projects()

	/**
	 * The names of a declared list that the collection projects, in order.
	 *
	 * @param mixed                $value      The declared list.
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function projectedNames(mixed $value, array $collection): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(
			array_filter(
				$value,
				fn ($name): bool => is_string($name) === true && $this->projects(collection: $collection, field: $name) === true
			)
		);
	}//end projectedNames()
}//end class
