<?php

/**
 * Portaliq Collection List Keys (site-mijn-omgeving-components)
 *
 * A `collection` block may show only its first rows (`limit`, 1 to 50) in a
 * declared order (`sort: {field, direction}` on a projected field); a
 * `calendar` block may show only today, this week or this month (`range`).
 * A value that does not fit is dropped and the block stays as it was.
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
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Normalises `limit` and `sort` on a collection block and `range` on a calendar block.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class CollectionListKeys {
	/**
	 * The most rows a collection block may show.
	 */
	private const MAX_LIMIT = 50;

	/**
	 * The ranges a calendar block may show.
	 */
	private const RANGES = ['day', 'week', 'month'];

	/**
	 * The `limit` and `sort` a collection block keeps.
	 *
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection it reads, or null when unknown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function collectionKeys(array $block, ?array $collection): array {
		$out = [];

		$limit = ($block['limit'] ?? null);
		if (is_int($limit) === true && $limit >= 1 && $limit <= self::MAX_LIMIT) {
			$out['limit'] = $limit;
		}

		$sort = ($block['sort'] ?? null);
		if (is_array($sort) === true
			&& $this->projects(collection: $collection, field: ($sort['field'] ?? null)) === true
			&& in_array(($sort['direction'] ?? null), ['asc', 'desc'], true) === true
		) {
			$out['sort'] = ['field' => $sort['field'], 'direction' => $sort['direction']];
		}

		return $out;
	}//end collectionKeys()

	/**
	 * The `range` a calendar block keeps: `day`, `week` or `month`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function calendarKeys(array $block): array {
		$range = ($block['range'] ?? null);
		if (in_array($range, self::RANGES, true) === true) {
			return ['range' => $range];
		}

		return [];
	}//end calendarKeys()

	/**
	 * Whether the collection projects this field (any field, when it projects none).
	 *
	 * @param array<string, mixed>|null $collection The collection.
	 * @param mixed                     $field      The declared field.
	 *
	 * @return bool
	 */
	private function projects(?array $collection, mixed $field): bool {
		if (is_string($field) === false || $field === '' || $collection === null) {
			return false;
		}

		$fields = ($collection['fields'] ?? null);
		return is_array($fields) === false || in_array($field, $fields, true) === true;
	}//end projects()
}//end class
