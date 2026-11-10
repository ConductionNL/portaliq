<?php

/**
 * Portaliq Catalogue Scope
 *
 * Which items a visitor's catalogue query keeps before its facet choices:
 * the item types, app, categories and range asked for, the query's words,
 * and whether only upcoming items count.
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
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * The scope of one PublicCatalogueQuery::run() call.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */
final class CatalogueScope {
	/**
	 * Constructor.
	 *
	 * @param array<int, string> $types      The item types to keep, or none for all.
	 * @param array<int, string> $words      The query's folded words.
	 * @param string             $today      Today, `Y-m-d`.
	 * @param string             $app        The app whose items to keep, or '' for all.
	 * @param array<int, string> $categories The categories to keep, or none for all.
	 * @param string             $range      `schoolYear`, or '' for any day.
	 * @param bool               $upcoming   Whether only items whose (end) date is today or later count.
	 */
	public function __construct(
		public readonly array $types,
		public readonly array $words,
		public readonly string $today,
		public readonly string $app,
		public readonly array $categories,
		public readonly string $range,
		public readonly bool $upcoming,
	) {
	}//end __construct()
}//end class
