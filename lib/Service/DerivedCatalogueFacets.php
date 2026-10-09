<?php

/**
 * Portaliq Derived Catalogue Facets (site-catalogue-follows-the-school-boards)
 *
 * The facets a catalogue block asks for besides the declared ones: the
 * kind of each item and a news item's audience. Plain logic, no reads.
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
 * Adds the kind and audience facets a catalogue block asks for.
 *
 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
 */
class DerivedCatalogueFacets {

	/**
	 * The items with the facets the block asks for besides the declared ones:
	 * the kind of each item under `kindFacet` (a news item's kind is
	 * `kindNews`, the word the page uses for news), and a news item's
	 * audience under `audienceFacet`. A label the item already declares is
	 * left as it is; an empty label asks for nothing.
	 *
	 * @param array<int, array<string, mixed>> $items  The portal's items.
	 * @param array<string, mixed>             $params The query.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
	 */
	public function apply(array $items, array $params): array {
		$kindLabel     = $this->label(value: ($params['kindFacet'] ?? ''));
		$kindNews      = $this->label(value: ($params['kindNews'] ?? ''));
		$audienceLabel = $this->label(value: ($params['audienceFacet'] ?? ''));
		if ($kindLabel === '' && $audienceLabel === '') {
			return $items;
		}

		return array_map(
			function (array $item) use ($kindLabel, $kindNews, $audienceLabel): array {
				$isNews = (($item['type'] ?? '') === PublicCatalogue::TYPE_NEWS);
				$kind   = trim((string)($item['kind'] ?? ''));
				if ($isNews === true) {
					$kind = $kindNews;
				}

				$item = $this->withFacet(item: $item, label: $kindLabel, value: $kind);
				if ($isNews === true) {
					$item = $this->withFacet(item: $item, label: $audienceLabel, value: trim((string)($item['audience'] ?? '')));
				}

				return $item;
			},
			$items
		);
	}//end apply()

	/**
	 * The item with one more facet, unless the label or the value is empty or
	 * the item declares that label itself.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param string               $label The facet.
	 * @param string               $value Its value.
	 *
	 * @return array<string, mixed>
	 */
	private function withFacet(array $item, string $label, string $value): array {
		if ($label === '' || $value === '' || isset($item['facets'][$label]) === true) {
			return $item;
		}

		$item['facets']         = (array)($item['facets'] ?? []);
		$item['facets'][$label] = [$value];

		return $item;
	}//end withFacet()

	/**
	 * A facet label or value from the address: a string, trimmed, at most 60
	 * characters; anything else is empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function label(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return mb_substr(trim($value), 0, 60);
	}//end label()

}//end class
