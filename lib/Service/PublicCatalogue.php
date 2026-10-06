<?php

/**
 * Portaliq Public Catalogue (portal-public-catalogue)
 *
 * What a visitor can find on a portal without signing in: the portal's own
 * public news, and every installed app's public index for that portal
 * (PublicIndexItems). Searched, filtered by facet, sorted and paged here, over
 * items the apps already decided are public; portaliq reads no object of an
 * app itself, so nothing an app keeps private can surface through it.
 *
 * The gathered items are kept for five minutes per portal in the distributed
 * cache; a visitor's query never reaches an app.
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

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\PublicIndexItems;
use OCP\App\IAppManager;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Gathers and queries a portal's public catalogue.
 *
 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
 */
class PublicCatalogue {
	/**
	 * The type of the portal's own news items.
	 */
	public const TYPE_NEWS = 'news';

	/**
	 * How long the gathered items are kept, in seconds.
	 */
	private const TTL = 300;

	/**
	 * The most results one page shows.
	 */
	public const MAX_PAGE = 50;

	/**
	 * The sorts a visitor may choose.
	 */
	public const SORTS = ['relevance', 'date', 'dateDesc', 'title'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager           $apps    The installed apps.
	 * @param PortalProviderLocator $locator Finds each app's provider.
	 * @param PublicNewsReader      $news    The portal's own public news.
	 * @param ICacheFactory         $caches  The distributed cache.
	 * @param LoggerInterface       $logger  Logs an app whose index failed.
	 */
	public function __construct(
		private readonly IAppManager $apps,
		private readonly PortalProviderLocator $locator,
		private readonly PublicNewsReader $news,
		private readonly ICacheFactory $caches,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every public item of one portal: its news, then each app's index.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-an-app-may-offer-a-portal-an-index-of-its-public-things
	 */
	public function itemsFor(string $portal): array {
		$cache = null;
		try {
			$cache  = $this->caches->createDistributed('portaliq-catalogue');
			$cached = $cache->get(md5($portal));
			if (is_array($cached) === true) {
				return $cached;
			}
		} catch (Throwable $e) {
			$cache = null;
		}

		$items = array_merge($this->newsItems(portal: $portal), $this->appItems(portal: $portal));
		try {
			$cache?->set(md5($portal), $items, self::TTL);
		} catch (Throwable $e) {
			// A cache that cannot hold the items only costs the next visitor a read.
			$this->logger->debug('Portaliq: catalogue not cached', ['reason' => $e->getMessage()]);
		}

		return $items;
	}//end itemsFor()

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
	 * @param array<string, mixed>             $params `q`, `types`, `filters`, `sort`, `page`, `limit`, `upcoming`, `today`.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, pages: int, facets: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue
	 */
	public function query(array $items, array $params): array {
		$types   = array_values(array_filter((array)($params['types'] ?? []), 'is_string'));
		$words   = $this->words(text: (string)($params['q'] ?? ''));
		$filters = $this->filters(declared: ($params['filters'] ?? []));
		$today   = (string)($params['today'] ?? gmdate('Y-m-d'));

		$base = array_values(
			array_filter(
				$items,
				fn (array $item): bool => ($types === [] || in_array($item['type'] ?? '', $types, true) === true)
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
	 * The portal's public news as catalogue items.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function newsItems(string $portal): array {
		$out = [];
		foreach ($this->news->allFor(portal: $portal) as $row) {
			$id    = (string)($row['id'] ?? '');
			$title = trim((string)($row['title'] ?? ''));
			if ($id === '' || $title === '') {
				continue;
			}

			$item = ['id' => 'news:' . $id, 'newsId' => $id, 'type' => self::TYPE_NEWS, 'kind' => '', 'title' => $title];
			if (trim((string)($row['intro'] ?? '')) !== '') {
				$item['summary'] = trim((string)$row['intro']);
			}

			if ((string)($row['publishedAt'] ?? '') !== '') {
				$item['date'] = (string)$row['publishedAt'];
			}

			if (trim((string)($row['audienceLabel'] ?? '')) !== '') {
				$item['meta'] = [trim((string)$row['audienceLabel'])];
			}

			$out[] = $item;
		}//end foreach

		return $out;
	}//end newsItems()

	/**
	 * Every installed app's public index for the portal, each answer held to
	 * its shape. An app that fails adds nothing and the rest still count.
	 *
	 * @param string $portal The portal slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function appItems(string $portal): array {
		$out   = [];
		$shape = new PublicIndexItems();
		foreach ($this->apps->getInstalledApps() as $appId) {
			$provider = $this->locator->locate(appId: (string)$appId);
			if ($provider === null || method_exists($provider, PublicIndexItems::METHOD) === false) {
				continue;
			}

			try {
				$answer = $provider->{PublicIndexItems::METHOD}($portal);
			} catch (Throwable $e) {
				$this->logger->warning('Portaliq: public index failed', ['app' => (string)$appId, 'reason' => $e->getMessage()]);
				continue;
			}

			$out = array_merge($out, $shape->items(appId: (string)$appId, entries: $answer));
		}

		return $out;
	}//end appItems()

	/**
	 * The words of a text, lower case and without accents.
	 *
	 * @param string $text The text.
	 *
	 * @return array<int, string>
	 */
	private function words(string $text): array {
		return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $this->fold(text: $text)) ?: [], static fn (string $word): bool => $word !== ''));
	}//end words()

	/**
	 * A text folded for matching: lower case, accents removed.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function fold(string $text): string {
		$text = mb_strtolower($text);
		if (class_exists(\Normalizer::class) === true) {
			$decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
			if (is_string($decomposed) === true) {
				$text = (string)preg_replace('/\p{Mn}+/u', '', $decomposed);
			}
		}

		return $text;
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

		$haystack = $this->fold(text: implode(' ', array_merge([(string)$item['title'], (string)($item['kind'] ?? ''), (string)($item['summary'] ?? '')], (array)($item['meta'] ?? []))));
		foreach ($words as $word) {
			if (str_contains($haystack, $word) === false) {
				return false;
			}
		}

		return true;
	}//end matches()

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
		foreach ((is_array($declared) === true ? $declared : []) as $label => $values) {
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
		$date  = static fn (array $item): string => (string)($item['date'] ?? '');
		$title = static fn (array $item): string => mb_strtolower((string)$item['title']);
		usort(
			$items,
			function (array $a, array $b) use ($sort, $words, $date, $title): int {
				return match ($sort) {
					'date'     => [$date($a) === '' ? 1 : 0, $date($a), $title($a)] <=> [$date($b) === '' ? 1 : 0, $date($b), $title($b)],
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
