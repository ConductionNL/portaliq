<?php

/**
 * Portaliq Catalogue Scope Filter
 *
 * Whether a catalogue item is in a visitor's scope: its type, app,
 * category and, for a school-year range, its day.
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
 * The scope check of PublicCatalogueQuery, built by it for each query.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */
final class CatalogueScopeFilter {
	/**
	 * Whether an item is of a wanted type, app and category, and in the wanted range.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param CatalogueScope       $scope The scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 */
	public function holds(array $item, CatalogueScope $scope): bool {
		return ($scope->types === [] || in_array($item['type'] ?? '', $scope->types, true) === true)
			&& ($scope->app === '' || str_starts_with((string)($item['id'] ?? ''), $scope->app . ':') === true)
			&& ($scope->categories === [] || in_array((string)($item['category'] ?? ''), $scope->categories, true) === true)
			&& ($scope->range !== 'schoolYear' || $this->inSchoolYear(item: $item, today: $scope->today) === true);
	}//end holds()

	/**
	 * Whether the item's day falls in the school year that holds today
	 * (1 August to 31 July).
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param string               $today Today, `Y-m-d`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-2
	 */
	private function inSchoolYear(array $item, string $today): bool {
		$day = substr((string)($item['date'] ?? ''), 0, 10);
		if ($day === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
			return false;
		}

		$year  = (int)substr($today, 0, 4);
		$start = ($year - 1);
		if ((int)substr($today, 5, 2) >= 8) {
			$start = $year;
		}

		return $day >= sprintf('%04d-08-01', $start) && $day <= sprintf('%04d-07-31', ($start + 1));
	}//end inSchoolYear()
}//end class
