<?php

/**
 * Portaliq Portal Slug Lookup
 *
 * Finds one `portal` row by its slug, in any status.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
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
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * One portal by slug, re-checked against the rows the reader returns.
 *
 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
 */
class PortalSlugLookup {

	/**
	 * Constructor.
	 *
	 * @param ScopedCollectionReaderInterface $reader Reads the portal rows.
	 */
	public function __construct(
		private readonly ScopedCollectionReaderInterface $reader,
	) {
	}//end __construct()

	/**
	 * The portal with this slug, or null. An empty slug finds nothing.
	 *
	 * @param string $slug The portal slug.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude Shared read helper; the @spec of each calling method covers the behaviour it reads for.
	 */
	public function find(string $slug): ?array {
		if ($slug === '') {
			return null;
		}

		$rows = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portal',
			scopeField: 'slug',
			subjectRef: $slug,
			organisation: '',
			limit: 2
		);
		foreach ($rows as $row) {
			if (is_array($row) === true && ($row['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end find()
}//end class
