<?php

/**
 * Portaliq Record Block Normaliser (contribution-record-page)
 *
 * The record page vocabulary: a page's `record`, the `recordField` and
 * `recordKey` a block narrows its rows to the open record with, and the
 * `kpi`, `calendar` and `news` blocks.
 *
 * SECURITY: presentation only. Every collection a block or source names must
 * resolve against the ALREADY trust-filtered collections of the same
 * contribution (the caller passes their surviving ids), and narrowing to a
 * record can only ever subset rows the server already scoped to the subject.
 * Anything malformed is dropped, never rewritten to something reachable.
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
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Validates the record page keys and the kpi, calendar and news blocks.
 *
 * @spec openspec/changes/contribution-record-page/tasks.md#T1
 */
class RecordBlockNormaliser {
	/**
	 * The news block shows this many items when it names no limit.
	 */
	private const NEWS_DEFAULT_LIMIT = 3;

	/**
	 * The most news items a block may show.
	 */
	private const NEWS_MAX_LIMIT = 20;

	/**
	 * A page's `record`, or null when its collection does not resolve.
	 *
	 * @param mixed $record The declared record.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-page-may-be-the-record-page-of-a-collection
	 */
	public function pageRecord(mixed $record, array $collectionIds): ?array {
		if (is_array($record) === false || in_array(($record['collection'] ?? null), $collectionIds, true) === false) {
			return null;
		}

		$out = ['collection' => $record['collection']];
		$titleFields = $this->strings(value: ($record['titleFields'] ?? null));
		if ($titleFields !== []) {
			$out['titleFields'] = $titleFields;
		}

		// The record's name as the page's heading and its place in the
		// breadcrumb (zuiddrecht-resident-pages-match-the-boards).
		return $out + (new BoardKeys())->recordKeys(record: $record);
	}//end pageRecord()

	/**
	 * A `kpi` block, or null when its collection does not resolve or no card survives.
	 *
	 * @param array<string, mixed> $block The declared block.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-kpi-block-must-show-figure-cards-from-one-row
	 */
	public function kpiBlock(array $block, array $collectionIds): ?array {
		$collection = ($block['collection'] ?? null);
		if (in_array($collection, $collectionIds, true) === false) {
			return null;
		}

		$cards = [];
		foreach ($this->listOf(value: ($block['cards'] ?? null)) as $card) {
			$entry = $this->card(card: $card);
			if ($entry !== null) {
				$cards[] = $entry;
			}
		}

		// A segmented bar (site-school-blocks) may stand without cards.
		$segmented = (new SchoolBlockKeys())->segmentedKeys(block: $block);
		if ($cards === [] && $segmented === []) {
			return null;
		}

		$out = $this->withLabel(declared: $block, entry: ['type' => 'kpi', 'collection' => $collection]);
		$out = (new RecordScopeNormaliser())->scope(declared: $block, entry: $out);
		$out['cards'] = $cards;
		// The figures on one line, as a strip (mijn-overview-follows-the-boards).
		if ($segmented === [] && $cards !== [] && ($block['display'] ?? null) === 'strip') {
			$out['display'] = 'strip';
		}

		return $this->withPickAndCaption(declared: $block, entry: $out) + $segmented;
	}//end kpiBlock()

	/**
	 * Copy a kpi block's valid `pick` (`desc` unless it says `asc`) and `caption`.
	 *
	 * @param array<string, mixed> $declared The declared block.
	 * @param array<string, mixed> $entry The normalised block.
	 *
	 * @return array<string, mixed>
	 */
	private function withPickAndCaption(array $declared, array $entry): array {
		$pick = ($declared['pick'] ?? null);
		if (is_array($pick) === true && $this->isName(value: ($pick['field'] ?? null)) === true) {
			$entry['pick'] = ['field' => $pick['field'], 'direction' => 'desc'];
			if (($pick['direction'] ?? null) === 'asc') {
				$entry['pick']['direction'] = 'asc';
			}
		}

		$caption = ($declared['caption'] ?? null);
		if (is_array($caption) === true && $this->isName(value: ($caption['field'] ?? null)) === true
			&& $this->isName(value: ($caption['label'] ?? null)) === true
		) {
			$entry['caption'] = ['field' => $caption['field'], 'label' => $caption['label']];
		}

		return $entry;
	}//end withPickAndCaption()

	/**
	 * A `calendar` block, or null when none of its sources resolves.
	 *
	 * @param array<string, mixed> $block The declared block.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-must-show-dated-rows-as-a-list-and-a-month
	 */
	public function calendarBlock(array $block, array $collectionIds): ?array {
		$sources = [];
		$normaliser = new CalendarSourceNormaliser();
		foreach ($this->listOf(value: ($block['sources'] ?? null)) as $source) {
			$entry = $normaliser->source(source: $source, collectionIds: $collectionIds);
			if ($entry !== null) {
				$sources[] = $entry;
			}
		}

		if ($sources === []) {
			return null;
		}

		$out = $this->withLabel(declared: $block, entry: ['type' => 'calendar']);
		$out['sources'] = $sources;
		return $out;
	}//end calendarBlock()

	/**
	 * A `news` block: its limit clamped to 1..20, 3 when it names none.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/contribution-record-page/specs/portal-contribution-contract/spec.md#requirement-a-news-block-must-show-the-subjects-latest-news
	 */
	public function newsBlock(array $block): array {
		$limit = self::NEWS_DEFAULT_LIMIT;
		if (is_int($block['limit'] ?? null) === true) {
			$limit = max(1, min(self::NEWS_MAX_LIMIT, $block['limit']));
		}

		return $this->withLabel(declared: $block, entry: ['type' => 'news', 'limit' => $limit]);
	}//end newsBlock()

	/**
	 * One kpi card, or null without a field and a label.
	 *
	 * @param mixed $card The declared card.
	 *
	 * @return array<string, mixed>|null
	 */
	private function card(mixed $card): ?array {
		if (is_array($card) === false || $this->isName(value: ($card['field'] ?? null)) === false
			|| $this->isName(value: ($card['label'] ?? null)) === false
		) {
			return null;
		}

		$out  = ['field' => $card['field'], 'label' => $card['label']];
		$unit = $this->countedWord(value: ($card['unit'] ?? null));
		if ($unit !== null) {
			$out['unit'] = $unit;
		}

		$details = $this->details(declared: ($card['details'] ?? null));
		if ($details !== []) {
			$out['details'] = $details;
		}

		if (($card['highlight'] ?? null) === true || ($card['highlight'] ?? null) === 'true') {
			$out['highlight'] = true;
		}

		// The words after the figure in a strip, "ziek" in "1 dag ziek"
		// (mijn-overview-follows-the-boards).
		$strip = ($card['stripLabel'] ?? null);
		if (is_string($strip) === true && trim($strip) !== '' && mb_strlen(trim($strip)) <= 40) {
			$out['stripLabel'] = trim($strip);
		}

		return $out;
	}//end card()

	/**
	 * A card's details: the well-formed `{field, label}` entries.
	 *
	 * @param mixed $declared The declared details.
	 *
	 * @return array<int, array{field: string, label: string|array{one: string, other: string}}>
	 */
	private function details(mixed $declared): array {
		$details = [];
		foreach ($this->listOf(value: $declared) as $detail) {
			if (is_array($detail) === false || $this->isName(value: ($detail['field'] ?? null)) === false) {
				continue;
			}

			$label = $this->countedWord(value: ($detail['label'] ?? null));
			if ($label !== null) {
				$details[] = ['field' => $detail['field'], 'label' => $label];
			}
		}

		return $details;
	}//end details()

	/**
	 * A word that follows a figure: a string, or `{one, other}` so "1 dag"
	 * and "5 dagen" both read right. Half a pair, or anything else, is null.
	 *
	 * @param mixed $value The declared word.
	 *
	 * @return string|array{one: string, other: string}|null
	 *
	 * @spec openspec/changes/kpi-unit-singular-and-plural/specs/portal-contribution-contract/spec.md#requirement-a-figure-cards-unit-may-name-its-singular-and-plural
	 */
	private function countedWord(mixed $value): string|array|null {
		if ($this->isName(value: $value) === true) {
			return $value;
		}

		if (is_array($value) === true && $this->isName(value: ($value['one'] ?? null)) === true
			&& $this->isName(value: ($value['other'] ?? null)) === true
		) {
			return ['one' => $value['one'], 'other' => $value['other']];
		}

		return null;
	}//end countedWord()

	/**
	 * A declared list, or [] for anything else.
	 *
	 * @param mixed $value The declared value.
	 *
	 * @return array<array-key, mixed>
	 */
	private function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()

	/**
	 * Copy a non-empty string `label` from a declared block.
	 *
	 * @param array<string, mixed> $declared The declared block.
	 * @param array<string, mixed> $entry The normalised block.
	 *
	 * @return array<string, mixed>
	 */
	private function withLabel(array $declared, array $entry): array {
		if ($this->isName(value: ($declared['label'] ?? null)) === true) {
			$entry['label'] = $declared['label'];
		}

		return $entry;
	}//end withLabel()

	/**
	 * The non-empty strings of a list, or [] for anything else.
	 *
	 * @param mixed $value The declared list.
	 *
	 * @return array<int, string>
	 */
	private function strings(mixed $value): array {
		return array_values(array_filter($this->listOf(value: $value), fn ($item): bool => $this->isName(value: $item)));
	}//end strings()

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
