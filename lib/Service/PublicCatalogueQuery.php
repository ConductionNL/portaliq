<?php

/**
 * Portaliq Public Catalogue Query (portal-public-catalogue)
 *
 * One page of a portal's public catalogue for a visitor's query: words,
 * item types, facet choices, a sort and a page, over the items
 * PublicCatalogue gathered. Plain logic, no reads.
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
 * Searches, filters, sorts and pages catalogue items.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */
class PublicCatalogueQuery {
	/**
	 * The most results one page shows.
	 */
	public const MAX_PAGE = 50;

	/**
	 * The accented letters of the languages a portal is written in, and the
	 * plain letter a search matches them by.
	 */
	private const ACCENTS = [
		'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
		'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
		'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n', 'ĳ' => 'ij',
	];

	/**
	 * The sorts a visitor may choose.
	 */
	public const SORTS = ['relevance', 'date', 'dateDesc', 'title'];

	/**
	 * One page of the items that match a visitor's query, with the facets of
	 * the items that match everything else, and how many there are in all.
	 *
	 * `types` keeps only those item types; `q` matches words in the title,
	 * kind, summary and meta lines (accents and case do not matter);
	 * `filters` maps a facet label to the values that count (within one facet
	 * any value counts, across facets all must); `upcoming` keeps items whose
	 * (end) date is today or later, with the day given as `today`.
	 *
	 * @param array<int, array<string, mixed>> $items  The portal's items.
	 * @param array<string, mixed>             $params `q`, `types`, `filters`, `sort`, `page`, `limit`, `upcoming`, `today`,
	 *                                                 `kindFacet`, `kindNews`, `audienceFacet`.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, facets: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
	 */
	public function run(array $items, array $params): array {
		$items   = (new DerivedCatalogueFacets())->apply(items: $items, params: $params);
		$types   = array_values(array_filter((array)($params['types'] ?? []), 'is_string'));
		$words   = $this->words(text: (string)($params['q'] ?? ''));
		$filters = $this->filters(declared: ($params['filters'] ?? []));
		$today   = (string)($params['today'] ?? gmdate('Y-m-d'));

		$app        = (string)($params['app'] ?? '');
		$categories = array_values(array_filter((array)($params['categories'] ?? []), 'is_string'));
		$range      = (string)($params['range'] ?? '');

		$scope = ['types' => $types, 'app' => $app, 'categories' => $categories, 'range' => $range, 'today' => $today];
		$base  = array_values(
			array_filter(
				$items,
				fn (array $item): bool => $this->inScope(item: $item, scope: $scope)
					&& $this->matches(item: $item, words: $words)
					&& (($params['upcoming'] ?? false) !== true || $this->isUpcoming(item: $item, today: $today))
			)
		);

		$hits  = array_values(array_filter($base, fn (array $item): bool => $this->inFilters(item: $item, filters: $filters)));
		$hits  = $this->sorted(items: $hits, sort: (string)($params['sort'] ?? 'relevance'), words: $words);
		$limit = max(1, min(self::MAX_PAGE, (int)($params['limit'] ?? 10)));
		$pages = max(1, (int)ceil(count($hits) / $limit));
		$page  = max(1, min($pages, (int)($params['page'] ?? 1)));

		return [
			'items'  => array_slice($hits, ($page - 1) * $limit, $limit),
			'total'  => count($hits),
			'page'   => $page,
			'pages'  => $pages,
			'facets' => $this->facets(items: $base, filters: $filters),
		];
	}//end query()

	/**
	 * Whether an item is of a wanted type, app and category, and in the wanted range.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param array<string, mixed> $scope The `types`, `app`, `categories`, `range` and `today` the query asks for.
	 *
	 * @return bool
	 */
	private function inScope(array $item, array $scope): bool {
		return ($scope['types'] === [] || in_array($item['type'] ?? '', $scope['types'], true) === true)
			&& ($scope['app'] === '' || str_starts_with((string)($item['id'] ?? ''), $scope['app'] . ':') === true)
			&& ($scope['categories'] === [] || in_array((string)($item['category'] ?? ''), $scope['categories'], true) === true)
			&& ($scope['range'] !== 'schoolYear' || $this->inSchoolYear(item: $item, today: $scope['today']) === true);
	}//end inScope()

	/**
	 * The words of a text, lower case and without accents.
	 *
	 * @param string $text The text.
	 *
	 * @return array<int, string>
	 */
	private function words(string $text): array {
		$parts = preg_split('/[^\p{L}\p{N}]+/u', $this->fold(text: $text));
		if ($parts === false) {
			return [];
		}

		return array_values(array_filter($parts, static fn (string $word): bool => $word !== ''));
	}//end words()

	/**
	 * A text folded for matching: lower case, accents removed.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function fold(string $text): string {
		return strtr(mb_strtolower($text), self::ACCENTS);
	}//end fold()

	/**
	 * Whether every word occurs in the item's searchable text.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param array<int, string>   $words The query's words.
	 *
	 * @return bool
	 */
	private function matches(array $item, array $words): bool {
		if ($words === []) {
			return true;
		}

		$parts    = array_merge([(string)$item['title'], (string)($item['kind'] ?? ''), (string)($item['summary'] ?? '')], (array)($item['meta'] ?? []));
		$haystack = $this->fold(text: implode(' ', $parts));
		foreach ($words as $word) {
			if (str_contains($haystack, $word) === false) {
				return false;
			}
		}

		return true;
	}//end matches()

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

	/**
	 * Whether an item's (end) date is today or later; an item without a date
	 * is not upcoming.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param string               $today The day, `YYYY-MM-DD`.
	 *
	 * @return bool
	 */
	private function isUpcoming(array $item, string $today): bool {
		$last = (string)($item['endDate'] ?? ($item['date'] ?? ''));

		return $last !== '' && substr($last, 0, 10) >= $today;
	}//end isUpcoming()

	/**
	 * The visitor's facet choices, label to values, strings only.
	 *
	 * @param mixed $declared The choices.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function filters(mixed $declared): array {
		$out = [];
		if (is_array($declared) === false) {
			return $out;
		}

		foreach ($declared as $label => $values) {
			$values = array_values(array_filter((array)$values, static fn ($value): bool => is_string($value) === true && $value !== ''));
			if (is_string($label) === true && $label !== '' && $values !== []) {
				$out[$label] = $values;
			}
		}

		return $out;
	}//end filters()

	/**
	 * Whether an item has, for every chosen facet, one of the chosen values.
	 *
	 * @param array<string, mixed>              $item    The item.
	 * @param array<string, array<int, string>> $filters The choices.
	 * @param string|null                       $except  A facet to leave out.
	 *
	 * @return bool
	 */
	private function inFilters(array $item, array $filters, ?string $except = null): bool {
		foreach ($filters as $label => $values) {
			if ($label === $except) {
				continue;
			}

			if (array_intersect((array)($item['facets'][$label] ?? []), $values) === []) {
				return false;
			}
		}

		return true;
	}//end inFilters()

	/**
	 * The facets of the items, each value with how many items hold it among
	 * those that match every OTHER facet's choice, in first-seen order.
	 *
	 * @param array<int, array<string, mixed>>  $items   The items that match the query.
	 * @param array<string, array<int, string>> $filters The choices.
	 *
	 * @return array<int, array{label: string, values: array<int, array{value: string, count: int, selected: bool}>}>
	 */
	private function facets(array $items, array $filters): array {
		$labels = [];
		foreach ($items as $item) {
			foreach (array_keys((array)($item['facets'] ?? [])) as $label) {
				$labels[(string)$label] = true;
			}
		}

		$out = [];
		foreach (array_keys($labels) as $label) {
			$counts = [];
			foreach ($items as $item) {
				if ($this->inFilters(item: $item, filters: $filters, except: $label) === false) {
					continue;
				}

				foreach ((array)($item['facets'][$label] ?? []) as $value) {
					$counts[$value] = ($counts[$value] ?? 0) + 1;
				}
			}

			$values = [];
			foreach ($counts as $value => $count) {
				$values[] = ['value' => (string)$value, 'count' => $count, 'selected' => in_array((string)$value, $filters[$label] ?? [], true)];
			}

			$out[] = ['label' => $label, 'values' => $values];
		}//end foreach

		return $out;
	}//end facets()

	/**
	 * The items in the chosen order: by relevance (a word in the title first,
	 * then by date), by date rising or falling, or by title.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 * @param string                           $sort  The sort.
	 * @param array<int, string>               $words The query's words.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sorted(array $items, string $sort, array $words): array {
		$date = static fn (array $item): string => (string)($item['date'] ?? '');
		// An item without a date sorts after every dated one when rising.
		$rising = static fn (array $item): string => str_pad($date($item), 1, '~');
		$title  = static fn (array $item): string => mb_strtolower((string)$item['title']);
		usort(
			$items,
			function (array $a, array $b) use ($sort, $words, $date, $rising, $title): int {
				return match ($sort) {
					'date'     => [$rising($a), $title($a)] <=> [$rising($b), $title($b)],
					'dateDesc' => [$date($b), $title($a)] <=> [$date($a), $title($b)],
					'title'    => $title($a) <=> $title($b),
					default    => [$this->titleHits(item: $b, words: $words), $date($b)] <=> [$this->titleHits(item: $a, words: $words), $date($a)],
				};
			}
		);

		return $items;
	}//end sorted()

	/**
	 * How many of the query's words occur in an item's title.
	 *
	 * @param array<string, mixed> $item  The item.
	 * @param array<int, string>   $words The words.
	 *
	 * @return int
	 */
	private function titleHits(array $item, array $words): int {
		$title = $this->fold(text: (string)$item['title']);

		return count(array_filter($words, static fn (string $word): bool => str_contains($title, $word)));
	}//end titleHits()
}//end class
