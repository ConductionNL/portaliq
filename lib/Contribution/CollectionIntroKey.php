<?php

/**
 * Portaliq Collection Intro Key (collection-intro)
 *
 * Any contributed collection may carry `intro`: a short text the portal shows
 * above the collection's list, for example an admin-set sentence above
 * "Mijn zaken". The text is plain text; the portal never renders it as HTML.
 *
 * The key is kept, trimmed, only when it is a non-empty string.
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
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a collection's `intro` when it is a non-empty string.
 *
 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t1
 */
class CollectionIntroKey {
	/**
	 * Keep `intro` trimmed when it holds text; drop it otherwise.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/archive/2026-10-30-collection-intro/tasks.md#t1
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('intro', $collection) === false) {
			return $collection;
		}

		$intro = $collection['intro'];
		if (is_string($intro) === false || trim($intro) === '') {
			unset($collection['intro']);
			return $collection;
		}

		$collection['intro'] = trim($intro);
		return $collection;
	}//end normalise()
}//end class
