<?php

/**
 * Portaliq media usage
 *
 * Counts, per media item, how many of its portal's records refer to it, and
 * keeps that count on the item, so the library can list what nothing uses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalResolver;

/**
 * Keeps each media item's `usedOn` count.
 *
 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
 */
class MediaUsage {
	/**
	 * The register the library and its users live in.
	 */
	private const REGISTER = 'portaliq';

	/**
	 * The most records of one schema read per portal.
	 */
	private const LIMIT = 1000;

	/**
	 * A `media:<id>` reference, and the address the portal logo stores.
	 */
	private const PATTERN = '~(?:media:|/content/media/)([A-Za-z0-9-]+)~';

	/**
	 * Constructor.
	 *
	 * @param PortalResolver $portals The published portals.
	 * @param PortalObjectReader $reader Reads a portal's records.
	 * @param PortalObjectWriter $writer Writes the count onto an item.
	 */
	public function __construct(
		private readonly PortalResolver $portals,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
	) {
	}//end __construct()

	/**
	 * How many of the records refer to each item: a record that names an
	 * item twice counts once.
	 *
	 * @param array<int, array<string, mixed>> $records The records that may refer to an item.
	 *
	 * @return array<string, int> Count by item id.
	 *
	 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
	 */
	public static function count(array $records): array {
		$counts = [];
		foreach ($records as $record) {
			$text = (string)json_encode(value: $record, flags: (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$found = [];
			preg_match_all(pattern: self::PATTERN, subject: $text, matches: $found);
			foreach (array_unique(array: $found[1]) as $id) {
				$counts[$id] = (($counts[$id] ?? 0) + 1);
			}
		}

		return $counts;
	}//end count()

	/**
	 * Recount every published portal's library and write the counts that
	 * changed.
	 *
	 * @return int How many items got a new count.
	 *
	 * @spec openspec/changes/media-usage-count/specs/site-page-seo-history-and-media/spec.md#requirement-a-media-item-knows-how-many-records-use-it-req-mu-001
	 */
	public function refresh(): int {
		$written = 0;
		foreach ($this->portals->allPublishedPortals() as $portal) {
			$slug = (string)($portal['slug'] ?? '');
			if ($slug === '') {
				continue;
			}

			$users  = array_merge([$portal], $this->read(schema: 'page', slug: $slug), $this->read(schema: 'newsItem', slug: $slug));
			$counts = self::count(records: $users);
			foreach ($this->read(schema: 'media', slug: $slug) as $item) {
				$id = (string)($item['uuid'] ?? $item['id'] ?? (($item['@self'] ?? [])['id'] ?? ''));
				if ($id === '') {
					continue;
				}

				$used = ($counts[$id] ?? 0);
				if (array_key_exists(key: 'usedOn', array: $item) === true && (int)$item['usedOn'] === $used) {
					continue;
				}

				$updated = $this->writer->updateObject(
					register: self::REGISTER,
					schema: 'media',
					scopeField: '',
					subjectRef: '',
					organisation: '',
					id: $id,
					data: ['usedOn' => $used]
				);
				if ($updated !== null) {
					$written++;
				}
			}//end foreach
		}//end foreach

		return $written;
	}//end refresh()

	/**
	 * One schema's records of a portal.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function read(string $schema, string $slug): array {
		return array_values(
			array_filter(
				array: $this->reader->readCollection(
					register: self::REGISTER,
					schema: $schema,
					scopeField: 'portal',
					subjectRef: $slug,
					limit: self::LIMIT
				),
				callback: 'is_array'
			)
		);
	}//end read()
}//end class
