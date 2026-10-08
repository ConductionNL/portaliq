<?php

/**
 * Portaliq Collection List Keys (site-mijn-omgeving-components)
 *
 * A `collection` block may show only its first rows (`limit`, 1 to 50) in a
 * declared order (`sort: {field, direction}` on a projected field), and as
 * cards naming each row (`display: cards`, `titleFields`, `progress`); a
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
	 * The longest block heading kept (collection-block-label).
	 */
	private const MAX_LABEL = 120;

	/**
	 * The `limit`, `sort` and `label` a collection block keeps.
	 *
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection it reads, or null when unknown.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 * @spec openspec/changes/collection-block-label/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-keeps-its-own-heading
	 */
	public function collectionKeys(array $block, ?array $collection): array {
		$out = [];

		$limit = ($block['limit'] ?? null);
		if (is_int($limit) === true && $limit >= 1 && $limit <= self::MAX_LIMIT) {
			$out['limit'] = $limit;
		}

		// The block's own heading ("Latest grades"), which wins over the
		// collection's label (collection-block-label).
		$label = ($block['label'] ?? null);
		if (is_string($label) === true && trim($label) !== '' && mb_strlen(trim($label)) <= self::MAX_LABEL) {
			$out['label'] = trim($label);
		}

		$sort = ($block['sort'] ?? null);
		if (is_array($sort) === true
			&& $this->projects(collection: $collection, field: ($sort['field'] ?? null)) === true
			&& in_array(($sort['direction'] ?? null), ['asc', 'desc'], true) === true
		) {
			$out['sort'] = ['field' => $sort['field'], 'direction' => $sort['direction']];
		}

		// The school displays (rows, bars, chips, richer cards), site-school-blocks.
		return $out + $this->cards(block: $block, collection: $collection) + (new DisplayKeys())->keys(block: $block, collection: $collection);
	}//end collectionKeys()

	/**
	 * `display: cards`, the `titleFields` that name each card, and its
	 * `progress: {valueField, totalField, label}`, every named field projected
	 * (REQ-SMO-028). A progress that does not fit is dropped and the cards
	 * stay.
	 *
	 * WHY `titleFields` IS KEPT HERE. REQ-SMO-028 says each card MUST show the
	 * row's title, and ProgressCards.vue falls back to `name`, `title` and
	 * `givenName` when nothing names the row. A schema with none of those three
	 * drew a bar and a number and nothing identifying, which is merely
	 * unhelpful with one row and unusable with several: the reader cannot tell
	 * which row a bar belongs to. Measured on learniq's workplace-trainer
	 * overview over `bpv-placement`, 4 October 2026. The key is the same
	 * `titleFields` a `tasks` or `inbox` block already takes, so an app that
	 * can name a task row can name a card with what it already knows.
	 *
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-show-its-rows-as-cards-with-a-progress-figure-req-smo-028
	 */
	private function cards(array $block, ?array $collection): array {
		if (($block['display'] ?? null) !== 'cards') {
			return [];
		}

		$out = ['display' => 'cards'];
		$titles = $this->titleFields(block: $block, collection: $collection);
		if ($titles !== []) {
			$out['titleFields'] = $titles;
		}

		$progress = ($block['progress'] ?? null);
		if (is_array($progress) === true
			&& $this->projects(collection: $collection, field: ($progress['valueField'] ?? null)) === true
			&& $this->projects(collection: $collection, field: ($progress['totalField'] ?? null)) === true
		) {
			$out['progress'] = ['valueField' => $progress['valueField'], 'totalField' => $progress['totalField']];
			if (is_string($progress['label'] ?? null) === true && trim($progress['label']) !== '') {
				$out['progress']['label'] = trim($progress['label']);
			}
		}

		return $out;
	}//end cards()

	/**
	 * The projected fields that name each card, in the order declared.
	 *
	 * @param array<string, mixed>      $block      The declared block.
	 * @param array<string, mixed>|null $collection The collection.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-show-its-rows-as-cards-with-a-progress-figure-req-smo-028
	 */
	private function titleFields(array $block, ?array $collection): array {
		$titles = [];
		foreach ((array)($block['titleFields'] ?? []) as $field) {
			if ($this->projects(collection: $collection, field: $field) === true) {
				$titles[] = $field;
			}
		}

		return $titles;
	}//end titleFields()

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
