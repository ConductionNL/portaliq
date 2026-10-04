<?php

/**
 * Portaliq Portal Block Resolver (contribution-manifest-v3)
 *
 * The block-level half of page resolution: the block-type registry and the
 * per-type reference rules.
 *
 * SECURITY: every reference resolves against the ALREADY trust-filtered and
 * sanitised collections/actions of the same contribution (the caller passes
 * their surviving ids), so a trust-dropped entry can never be reached through
 * a surviving block. A block of an unknown type, or one whose reference does
 * not resolve, is dropped — never rewritten to something reachable.
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
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Filters a page's blocks to the registry with resolvable references.
 *
 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
 */
class PortalBlockResolver {
	/**
	 * The page ids of the contribution whose blocks are being resolved.
	 *
	 * @var array<int, string>
	 */
	private array $pageIds = [];

	/**
	 * The block-type registry. A block of any other type is dropped.
	 */
	private const BLOCK_TYPES = [
		'collection',
		'action',
		'detail',
		'richText',
		'cta',
		'citizenCase',
		'kpi',
		'calendar',
		'news',
		'tasks',
		'inbox',
		'cases',
		'steps',
		'documents',
		'timeline',
	];

	/**
	 * The block types whose whole body is a reference to a collection.
	 *
	 * The citizen case block (what-the-citizen-may-write-on-their-own-case)
	 * references a collection like `detail` does; what it may write is
	 * resolved per request from the case type, never from the block.
	 */
	private const COLLECTION_BLOCK_TYPES = ['collection', 'detail', 'citizenCase'];

	/**
	 * Filter a page's blocks to the registry with resolvable references.
	 *
	 * @param mixed $blocks The declared blocks.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 * @param array<int, string> $actionIds The valid action ids.
	 * @param array<int, array<string, mixed>> $collections The sanitised collections
	 *                                                      (the `tasks` and `inbox`
	 *                                                      blocks read their kind
	 *                                                      and projected fields).
	 * @param array<int, string> $pageIds The contribution's page ids (a `cta` may name one).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	public function normaliseBlocks(mixed $blocks, array $collectionIds, array $actionIds, array $collections=[], array $pageIds=[]): array {
		$this->pageIds = $pageIds;
		if (is_array($blocks) === false) {
			return [];
		}

		$out = [];
		foreach ($blocks as $block) {
			$entry = $this->normaliseBlock(
				block: $block,
				collectionIds: $collectionIds,
				actionIds: $actionIds,
				collections: $collections
			);
			if ($entry !== null) {
				$out[] = $entry;
			}
		}

		return $out;
	}//end normaliseBlocks()

	/**
	 * Resolve ONE block against the surviving references, or null when its type
	 * is outside the registry or its reference does not resolve.
	 *
	 * @param mixed $block The declared block.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 * @param array<int, string> $actionIds The valid action ids.
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normaliseBlock(mixed $block, array $collectionIds, array $actionIds, array $collections): ?array {
		if (is_array($block) === false) {
			return null;
		}

		$type = ($block['type'] ?? null);
		if (in_array($type, self::BLOCK_TYPES, true) === false) {
			return null;
		}

		if (in_array($type, self::COLLECTION_BLOCK_TYPES, true) === true) {
			$entry = $this->referenceBlock(
				type: $type,
				key: 'collection',
				ref: ($block['collection'] ?? null),
				allowed: $collectionIds
			);
			if ($entry === null) {
				return null;
			}

			return $this->collectionBlock(type: $type, block: $block, entry: $entry, collectionIds: $collectionIds, collections: $collections);
		}

		if (in_array($type, ['kpi', 'calendar', 'news', 'tasks', 'inbox', 'cases', 'steps', 'documents', 'timeline'], true) === true) {
			return $this->ownRulesBlock(type: $type, block: $block, collectionIds: $collectionIds, collections: $collections);
		}

		if ($type === 'action') {
			return $this->referenceBlock(
				type: 'action',
				key: 'action',
				ref: ($block['action'] ?? null),
				allowed: $actionIds
			);
		}

		if ($type === 'cta') {
			return (new CtaBlockNormaliser())->normalise(block: $block, actionIds: $actionIds, pageIds: $this->pageIds);
		}

		return $this->richTextBlock(block: $block);
	}//end normaliseBlock()

	/**
	 * A `collection`, `detail` or `citizenCase` block whose reference
	 * resolved: the record scope and lookups (contribution-record-page), and
	 * on a `collection` block its `limit` and `sort`
	 * (site-mijn-omgeving-components REQ-SMO-021).
	 *
	 * @param string $type The block type.
	 * @param array<string, mixed> $block The declared block.
	 * @param array<string, mixed> $entry The block as resolved so far.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	private function collectionBlock(string $type, array $block, array $entry, array $collectionIds, array $collections): array {
		// A block on a record page may narrow its rows to the open record
		// (contribution-record-page).
		$scopes = new RecordScopeNormaliser();
		$entry = $scopes->scope(declared: $block, entry: $entry);
		$entry = $scopes->lookups(declared: $block, entry: $entry, collectionIds: $collectionIds);

		if ($type !== 'collection') {
			return $entry;
		}

		$collection = null;
		foreach ($collections as $candidate) {
			if (($candidate['id'] ?? null) === $entry['collection']) {
				$collection = $candidate;
			}
		}

		return $entry + (new CollectionListKeys())->collectionKeys(block: $block, collection: $collection);
	}//end collectionBlock()

	/**
	 * A block whose type has a normaliser of its own, or null when its
	 * references do not resolve.
	 *
	 * The `tasks`, `inbox`, `cases`, `steps`, `documents` and `timeline`
	 * blocks show what the resident still has to do, their newest messages,
	 * their cases, and where one case stands, its documents and its history
	 * (site-mijn-omgeving-components REQ-SMO-021).
	 *
	 * @param string $type The block type.
	 * @param array<string, mixed> $block The declared block.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 * @param array<int, array<string, mixed>> $collections The sanitised collections.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
	 */
	private function ownRulesBlock(string $type, array $block, array $collectionIds, array $collections): ?array {
		$lists = new ListBlockNormaliser();
		$builders = [
			'tasks' => static fn (): ?array => $lists->tasksBlock(block: $block, collections: $collections),
			'inbox' => static fn (): ?array => $lists->inboxBlock(block: $block, collections: $collections),
			'cases' => static fn (): ?array => $lists->casesBlock(block: $block, collections: $collections),
		];
		if (isset($builders[$type]) === true) {
			return $builders[$type]();
		}

		if (in_array($type, ListBlockNormaliser::RECORD_BLOCKS, true) === true) {
			return $lists->recordBlock(type: $type, block: $block, collections: $collections);
		}

		return $this->recordPageBlock(type: $type, block: $block, collectionIds: $collectionIds);
	}//end ownRulesBlock()

	/**
	 * A `kpi`, `calendar` or `news` block (contribution-record-page), or null
	 * when its collections do not resolve.
	 *
	 * @param string $type The block type.
	 * @param array<string, mixed> $block The declared block.
	 * @param array<int, string> $collectionIds The valid collection ids.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/contribution-record-page/tasks.md#T1
	 */
	private function recordPageBlock(string $type, array $block, array $collectionIds): ?array {
		$blocks = new RecordBlockNormaliser();
		if ($type === 'kpi') {
			return $blocks->kpiBlock(block: $block, collectionIds: $collectionIds);
		}

		if ($type === 'calendar') {
			$calendar = $blocks->calendarBlock(block: $block, collectionIds: $collectionIds);
			// Today, this week or this month (site-mijn-omgeving-components REQ-SMO-021).
			if ($calendar === null) {
				return null;
			}

			return $calendar + (new CollectionListKeys())->calendarKeys(block: $block);
		}

		return $blocks->newsBlock(block: $block);
	}//end recordPageBlock()

	/**
	 * The richText block, or null when it carries no markdown to render.
	 *
	 * Split out of normaliseBlock() so the type switch above stays a switch
	 * and does not also carry the last type's own validation.
	 *
	 * @param array<string, mixed> $block The declared block.
	 *
	 * @return array<string, mixed>|null
	 */
	private function richTextBlock(array $block): ?array {
		$markdown = ($block['markdown'] ?? null);
		if (is_string($markdown) === true && $markdown !== '') {
			return ['type' => 'richText', 'markdown' => $markdown];
		}

		// A text filled from the open record (REQ-SMO-027): `template` with
		// `{field}` placeholders, and `whenEmpty` per field.
		$template = ($block['template'] ?? null);
		if (is_string($template) === false || trim($template) === '') {
			return null;
		}

		$entry = ['type' => 'richText', 'template' => $template];
		$whenEmpty = array_filter(
			(array)($block['whenEmpty'] ?? []),
			static fn ($text, $field): bool => is_string($field) === true && is_string($text) === true && $text !== '',
			ARRAY_FILTER_USE_BOTH
		);
		if ($whenEmpty !== []) {
			$entry['whenEmpty'] = $whenEmpty;
		}

		return $entry;
	}//end richTextBlock()

	/**
	 * A block that is nothing but a resolvable reference, or null when the
	 * reference does not resolve against the surviving ids.
	 *
	 * @param string $type The block type to emit.
	 * @param string $key The reference key to emit.
	 * @param mixed $ref The declared reference.
	 * @param array<int, string> $allowed The ids the reference may resolve to.
	 *
	 * @return array<string, mixed>|null
	 */
	private function referenceBlock(string $type, string $key, mixed $ref, array $allowed): ?array {
		if (in_array($ref, $allowed, true) === false) {
			return null;
		}

		return ['type' => $type, $key => $ref];
	}//end referenceBlock()
}//end class
