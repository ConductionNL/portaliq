<?php

/**
 * Portaliq List Block Normaliser (site-mijn-omgeving-components)
 *
 * The `tasks` and `inbox` blocks of a contributed page: what a resident still
 * has to do, and their newest messages, as action rows.
 *
 * SECURITY: a block only names collections of its own contribution, which
 * the caller hands in already trust-filtered. A `tasks` block that names no
 * such collection is dropped. An `inbox` block that names a collection which
 * is not a `kind: inbox` collection is dropped too, never widened to every
 * inbox. Optional keys that do not fit are dropped and the block stays.
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
 * Normalises the `tasks` and `inbox` blocks.
 *
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
class ListBlockNormaliser {
	/**
	 * The blocks that read one open record through a provider of the same
	 * name on its collection.
	 */
	public const RECORD_BLOCKS = ['steps', 'documents', 'timeline'];

	/**
	 * The most rows a list block may ask for.
	 */
	private const MAX_LIMIT = 50;

	/**
	 * The longest heading a list block may carry.
	 */
	private const MAX_LABEL_LENGTH = 120;

	/**
	 * A `tasks` block, or null when it names no collection of the contribution.
	 *
	 * Keeps `dueField` and `titleFields` only when they name projected fields
	 * (any field, when the collection projects none), `limit` from 1 to 50,
	 * and a short `label`.
	 *
	 * @param array<string, mixed>             $block       The declared block.
	 * @param array<int, array<string, mixed>> $collections The contribution's sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function tasksBlock(array $block, array $collections): ?array {
		$collection = $this->collection(id: ($block['collection'] ?? null), collections: $collections);
		if ($collection === null) {
			return null;
		}

		$entry = ['type' => 'tasks', 'collection' => $collection['id']];

		$due = ($block['dueField'] ?? null);
		if (is_string($due) === true && $this->projects(collection: $collection, field: $due) === true) {
			$entry['dueField'] = $due;
		}

		$titles = $this->projectedNames(value: ($block['titleFields'] ?? null), collection: $collection);
		if ($titles !== []) {
			$entry['titleFields'] = $titles;
		}

		// The record scope and lookups a collection block takes, plus
		// excludeWhen over a declared lookup (REQ-SMO-025).
		$scopes = new RecordScopeNormaliser();
		$entry = $scopes->scope(declared: $block, entry: $entry);
		$entry = $scopes->lookups(declared: $block, entry: $entry, collectionIds: $this->ids(collections: $collections));
		$entry += $this->excludeWhen(declared: ($block['excludeWhen'] ?? null), lookups: ($entry['lookups'] ?? []));

		// The highlight card (site-school-blocks).
		return $entry + $this->common(block: $block) + (new SchoolBlockKeys())->tasksKeys(block: $block, collection: $collection)
			+ (new BoardKeys())->tasksKeys(block: $block);
	}//end tasksBlock()

	/**
	 * A tasks block's `excludeWhen: {lookup, in}`, kept only when it names a
	 * lookup the block declares and lists scalar values.
	 *
	 * @param mixed                            $declared The declared value.
	 * @param array<int, array<string, mixed>> $lookups  The block's normalised lookups.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-tasks-and-inbox-blocks-may-narrow-to-the-open-record-and-leave-rows-out-by-a-lookup-req-smo-025
	 */
	private function excludeWhen(mixed $declared, array $lookups): array {
		if (is_array($declared) === false || in_array(($declared['lookup'] ?? null), array_column($lookups, 'as'), true) === false) {
			return [];
		}

		$values = array_values(array_filter((array)($declared['in'] ?? null), static fn ($value): bool => is_scalar($value) === true));
		if ($values === []) {
			return [];
		}

		return ['excludeWhen' => ['lookup' => $declared['lookup'], 'in' => $values]];
	}//end excludeWhen()

	/**
	 * The ids of the collections.
	 *
	 * @param array<int, array<string, mixed>> $collections The collections.
	 *
	 * @return array<int, string>
	 */
	private function ids(array $collections): array {
		return array_values(array_filter(array_column($collections, 'id'), static fn ($id): bool => is_string($id) === true && $id !== ''));
	}//end ids()

	/**
	 * An `inbox` block, or null when it names a collection that is not a
	 * `kind: inbox` collection of the contribution.
	 *
	 * Without a collection the block reads every inbox of the resident.
	 *
	 * @param array<string, mixed>             $block       The declared block.
	 * @param array<int, array<string, mixed>> $collections The contribution's sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function inboxBlock(array $block, array $collections): ?array {
		$entry = ['type' => 'inbox'];

		if (array_key_exists('collection', $block) === true) {
			$collection = $this->collection(id: $block['collection'], collections: $collections);
			if ($collection === null || ($collection['kind'] ?? null) !== 'inbox') {
				return null;
			}

			$entry['collection'] = $collection['id'];
		}

		// Only messages about the open record (REQ-SMO-025).
		$recordField = ($block['recordField'] ?? null);
		if (is_string($recordField) === true && preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $recordField) === 1) {
			$entry['recordField'] = $recordField;
		}

		// The plain list (zuiddrecht-resident-pages-match-the-boards).
		return $entry + $this->common(block: $block) + (new BoardKeys())->inboxKeys(block: $block);
	}//end inboxBlock()

	/**
	 * A `cases` block, or null when it names no `kind: cases` collection of
	 * the contribution. `open: true` shows only running cases.
	 *
	 * @param array<string, mixed>             $block       The declared block.
	 * @param array<int, array<string, mixed>> $collections The contribution's sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function casesBlock(array $block, array $collections): ?array {
		$collection = $this->collection(id: ($block['collection'] ?? null), collections: $collections);
		if ($collection === null || ($collection['kind'] ?? null) !== 'cases') {
			return null;
		}

		$entry = ['type' => 'cases', 'collection' => $collection['id']];
		if (($block['open'] ?? null) === true) {
			$entry['open'] = true;
		}

		// The compact card, the link to every case and the resident's turn
		// (zuiddrecht-resident-pages-match-the-boards).
		return $entry + $this->common(block: $block) + (new BoardKeys())->casesKeys(block: $block);
	}//end casesBlock()

	/**
	 * A block that reads one open record through its collection's provider:
	 * `steps`, `documents` or `timeline`, or null when the collection declares
	 * no provider of that name. Whether the page is that collection's record
	 * page is the page resolver's check (recordPageOnly()).
	 *
	 * @param string                           $type        `steps`, `documents` or `timeline`.
	 * @param array<string, mixed>             $block       The declared block.
	 * @param array<int, array<string, mixed>> $collections The contribution's sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 * @spec openspec/changes/steps-as-bars/specs/site-mijn-omgeving/spec.md#requirement-the-steps-may-draw-as-a-row-of-bars
	 */
	public function recordBlock(string $type, array $block, array $collections): ?array {
		$collection = $this->collection(id: ($block['collection'] ?? null), collections: $collections);
		if (in_array($type, self::RECORD_BLOCKS, true) === false
			|| $collection === null
			|| is_array($collection[$type] ?? null) === false
		) {
			return null;
		}

		$entry = ['type' => $type, 'collection' => $collection['id']] + $this->common(block: $block);
		if ($type === 'documents') {
			// The upload button beside the heading (zuiddrecht-resident-pages-match-the-boards).
			$entry += (new BoardKeys())->documentsKeys(block: $block);
		}

		// The steps as a row of bars, as the school boards draw them (steps-as-bars).
		if ($type === 'steps' && ($block['display'] ?? null) === 'bars') {
			$entry['display'] = 'bars';
		}

		return $entry;
	}//end recordBlock()

	/**
	 * The blocks without a `steps`, `documents` or `timeline` block that does
	 * not read the page's own record collection: each belongs to one open
	 * record.
	 *
	 * @param array<int, array<string, mixed>> $blocks            The page's normalised blocks.
	 * @param array<int, string>               $recordCollections The page's `record` and `records` collections.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function recordPageOnly(array $blocks, array $recordCollections): array {
		return array_values(
			array_filter(
				$blocks,
				static fn (array $block): bool => in_array(($block['type'] ?? null), self::RECORD_BLOCKS, true) === false
					|| in_array(($block['collection'] ?? null), $recordCollections, true) === true
			)
		);
	}//end recordPageOnly()

	/**
	 * The keys every list block shares: `limit` and `label`.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>
	 */
	private function common(array $block): array {
		$out = [];

		$limit = ($block['limit'] ?? null);
		if (is_int($limit) === true && $limit >= 1 && $limit <= self::MAX_LIMIT) {
			$out['limit'] = $limit;
		}

		$label = ($block['label'] ?? null);
		if (is_string($label) === true) {
			$label = trim($label);
			if ($label !== '' && mb_strlen($label) <= self::MAX_LABEL_LENGTH) {
				$out['label'] = $label;
			}
		}

		return $out;
	}//end common()

	/**
	 * The collection with this id, or null when the contribution has none.
	 *
	 * @param mixed                            $id          The declared id.
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 */
	private function collection(mixed $id, array $collections): ?array {
		if (is_string($id) === false || $id === '') {
			return null;
		}

		foreach ($collections as $collection) {
			if (($collection['id'] ?? null) === $id) {
				return $collection;
			}
		}

		return null;
	}//end collection()

	/**
	 * Whether the collection projects this field (any field, when it projects none).
	 *
	 * @param array<string, mixed> $collection The collection.
	 * @param string               $field      The field.
	 *
	 * @return bool
	 */
	private function projects(array $collection, string $field): bool {
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
	 */
	private function projectedNames(mixed $value, array $collection): array {
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
