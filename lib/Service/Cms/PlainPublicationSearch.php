<?php

/**
 * The plain publication search: a GET form, the results and the pages.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCP\IL10N;

/**
 * The view model of a `federatedSearch` widget on the plain page.
 *
 * The form submits to `/site/plain` with the route and the search term
 * (`_search`), the results link to the plain detail page under the widget's
 * `detailRoute`, and previous and next carry `_page`. When the publications
 * cannot be read the model says so (`unavailable`) and carries no total and no
 * results: an outage never reads as "nothing found" (REQ-SHJ-005).
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-when-publications-cannot-be-read-the-page-says-so-req-shj-005
 */
class PlainPublicationSearch {

	/**
	 * The endpoint the search block reads when the page names none.
	 *
	 * @var string
	 */
	public const DEFAULT_ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	/**
	 * The most rows one page may ask for.
	 *
	 * @var int
	 */
	private const MAX_PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param PlainPublicationReader $reader The anonymous read.
	 * @param IL10N                  $l10n   The words.
	 * @param PlainDates             $dates  Dates as a reader writes them.
	 */
	public function __construct(
		private readonly PlainPublicationReader $reader,
		private readonly IL10N $l10n,
		private readonly PlainDates $dates,
	) {
	}//end __construct()

	/**
	 * The search block of one widget.
	 *
	 * @param array<string, mixed> $props   The widget's props.
	 * @param string               $route   The route of the page it is on.
	 * @param string               $query   The request's `_search`.
	 * @param int                  $page    The request's `_page`, from 1.
	 * @param PlainAddress         $address The page's addresses.
	 * @param string               $locale  The document language.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
	 */
	public function build(array $props, string $route, string $query, int $page, PlainAddress $address, string $locale): array {
		$page = max(1, $page);
		$pageSize = $this->pageSize(value: $props['pageSize'] ?? null);
		$endpoint = $this->string(value: $props['endpoint'] ?? null, fallback: self::DEFAULT_ENDPOINT);
		$detailRoute = '/' . trim($this->string(value: $props['detailRoute'] ?? null, fallback: '/publicatie'), '/');
		$full = $address->full(route: $route, extra: $this->searchParams(query: $query, page: $page));

		$block = [
			'kind' => 'search',
			'title' => $this->string(value: $props['title'] ?? null, fallback: ''),
			'action' => $address->plainBase(),
			'route' => '/' . trim($route, '/'),
			'portal' => $address->portalSlug(),
			'label' => $this->string(value: $props['inputLabel'] ?? null, fallback: $this->l10n->t('Search term')),
			'submit' => $this->string(value: $props['submitLabel'] ?? null, fallback: $this->l10n->t('Search')),
			'query' => $query,
			'fullUrl' => $full,
			'fullLabel' => $this->l10n->t('Open the full page'),
		];

		if ($this->reader->isLocal(endpoint: $endpoint) === false) {
			return $block + $this->unavailable();
		}

		$found = $this->reader->search(endpoint: $endpoint, query: $query, page: $page, pageSize: $pageSize);
		if ($found['state'] !== 'ok') {
			return $block + $this->unavailable();
		}

		$total = $found['total'];
		$pages = max(1, (int)ceil($total / $pageSize));
		$results = [];
		foreach ($found['results'] as $result) {
			$results[] = [
				'title' => ($result['title'] !== '' ? $result['title'] : $this->l10n->t('Untitled')),
				'href' => $this->detailHref(address: $address, detailRoute: $detailRoute, id: $result['id']),
				'date' => $this->dates->readable(value: $result['date'], locale: $locale),
				'summary' => $result['summary'],
			];
		}

		return $block + [
			'state' => 'ok',
			'sentence' => '',
			'total' => $total,
			'totalText' => $this->l10n->n('%n result', '%n results', $total),
			'empty' => ($total === 0 ? $this->l10n->t('No publications match this search.') : ''),
			'results' => $results,
			'page' => $page,
			'pages' => $pages,
			'previous' => ($page > 1 ? $address->plain(route: $route, extra: $this->searchParams(query: $query, page: $page - 1)) : ''),
			'next' => ($page < $pages ? $address->plain(route: $route, extra: $this->searchParams(query: $query, page: $page + 1)) : ''),
			'previousLabel' => $this->l10n->t('Previous page'),
			'nextLabel' => $this->l10n->t('Next page'),
			'pageText' => $this->l10n->t('Page %1$d of %2$d', [$page, $pages]),
		];
	}//end build()

	/**
	 * What a search that could not be read shows: the sentence, no total and
	 * no results.
	 *
	 * @return array<string, mixed>
	 */
	private function unavailable(): array {
		return [
			'state' => 'unavailable',
			'sentence' => $this->l10n->t('The publications cannot be shown here right now.'),
			'results' => [],
		];
	}//end unavailable()

	/**
	 * The `_search` and `_page` of an address; page 1 is left out.
	 *
	 * @param string $query The term.
	 * @param int    $page  The page.
	 *
	 * @return array<string, string>
	 */
	private function searchParams(string $query, int $page): array {
		$params = [];
		if ($query !== '') {
			$params['_search'] = $query;
		}

		if ($page > 1) {
			$params['_page'] = (string)$page;
		}

		return $params;
	}//end searchParams()

	/**
	 * The plain detail page of one result, or '' when it has no id.
	 *
	 * @param PlainAddress $address     The addresses.
	 * @param string       $detailRoute The detail page's route.
	 * @param string       $id          The publication's id.
	 *
	 * @return string
	 */
	private function detailHref(PlainAddress $address, string $detailRoute, string $id): string {
		if ($id === '') {
			return '';
		}

		return $address->plain(route: rtrim($detailRoute, '/') . '/' . $id);
	}//end detailHref()

	/**
	 * Rows per page: the widget's own, else 10, at most 100.
	 *
	 * @param mixed $value The prop.
	 *
	 * @return int
	 */
	private function pageSize(mixed $value): int {
		if (is_numeric($value) === false || (int)$value < 1) {
			return 10;
		}

		return min(self::MAX_PAGE_SIZE, (int)$value);
	}//end pageSize()

	/**
	 * A prop as a trimmed string, else the fallback.
	 *
	 * @param mixed  $value    The prop.
	 * @param string $fallback The default.
	 *
	 * @return string
	 */
	private function string(mixed $value, string $fallback): string {
		if (is_string($value) === true && trim($value) !== '') {
			return trim($value);
		}

		return $fallback;
	}//end string()
}//end class
