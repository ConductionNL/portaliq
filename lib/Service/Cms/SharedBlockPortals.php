<?php

/**
 * Portaliq Shared Block Portals (site-shared-page-blocks)
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
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
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Which portals a write to a shared block can touch.
 *
 * A shared block belongs to an organisation and to no portal, so the cache
 * listener cannot read a portal from the write. The portals it may show on
 * are the portals of the block's organisation.
 *
 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
 */
class SharedBlockPortals {
	/**
	 * Whether the written data is a shared block: an organisation, no portal, and a widget grid.
	 *
	 * @param array<string, mixed> $data The written object's data.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
	 */
	public static function isBlock(array $data): bool {
		if ((string)($data['organisation'] ?? '') === '' || (string)($data['portal'] ?? $data['slug'] ?? '') !== '') {
			return false;
		}

		return array_key_exists('widgets', $data) === true || array_key_exists('draftWidgets', $data) === true;
	}//end isBlock()

	/**
	 * The slugs of the portals of the block's organisation.
	 *
	 * @param array<string, mixed>             $data    The written block's data.
	 * @param array<int, array<string, mixed>> $portals The published portals.
	 *
	 * @return string[] The slugs, once each; none when the data is not a block.
	 *
	 * @spec openspec/changes/site-shared-page-blocks/tasks.md#t03
	 */
	public static function slugsFor(array $data, array $portals): array {
		if (self::isBlock(data: $data) === false) {
			return [];
		}

		$slugs = [];
		foreach ($portals as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug !== '' && (string)($portal['organisation'] ?? '') === (string)$data['organisation'] && in_array($slug, $slugs, true) === false) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end slugsFor()
}//end class
