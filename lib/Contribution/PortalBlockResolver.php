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
	 * The block-type registry. A block of any other type is dropped.
	 */
	private const BLOCK_TYPES = ['collection', 'action', 'detail', 'richText', 'cta', 'citizenCase', 'kpi', 'calendar', 'news'];

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
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-09-29-contribution-manifest-v3/tasks.md#T2
	 */
	public function normaliseBlocks(mixed $blocks, array $collectionIds, array $actionIds): array {
		if (is_array($blocks) === false) {
			return [];
		}

		$out = [];
		foreach ($blocks as $block) {
			$entry = $this->normaliseBlock(block: $block, collectionIds: $collectionIds, actionIds: $actionIds);
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
	 *
	 * @return array<string, mixed>|null
	 */
	private function normaliseBlock(mixed $block, array $collectionIds, array $actionIds): ?array {
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

			// A block on a record page may narrow its rows to the open record
			// (contribution-record-page).
			$scopes = new RecordScopeNormaliser();
			$entry = $scopes->scope(declared: $block, entry: $entry);
			return $scopes->lookups(declared: $block, entry: $entry, collectionIds: $collectionIds);
		}

		if (in_array($type, ['kpi', 'calendar', 'news'], true) === true) {
			return $this->recordPageBlock(type: $type, block: $block, collectionIds: $collectionIds);
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
			return $this->normaliseCtaBlock(block: $block, actionIds: $actionIds);
		}

		return $this->richTextBlock(block: $block);
	}//end normaliseBlock()

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
			return $blocks->calendarBlock(block: $block, collectionIds: $collectionIds);
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

		return null;
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

	/**
	 * A `cta` block: a resolvable action reference AND a non-empty label.
	 *
	 * @param array<string, mixed> $block The declared block.
	 * @param array<int, string> $actionIds The valid action ids.
	 *
	 * @return array<string, mixed>|null
	 */
	private function normaliseCtaBlock(array $block, array $actionIds): ?array {
		$ref = ($block['action'] ?? null);
		$label = ($block['label'] ?? null);
		if (in_array($ref, $actionIds, true) === false || is_string($label) === false || $label === '') {
			return null;
		}

		return ['type' => 'cta', 'action' => $ref, 'label' => $label];
	}//end normaliseCtaBlock()
}//end class
