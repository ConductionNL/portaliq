<?php

/**
 * Portaliq plain publication blocks
 *
 * The publication search and the publication detail of a site page, as the
 * plain version renders them without JavaScript.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use DateTimeImmutable;
use OCP\IL10N;
use Throwable;

/**
 * Builds the view model of a `federatedSearch` and a `publicationDetail`
 * widget, from the widget's own props (`endpoint`, `pageSize`, `detailRoute`)
 * and what PlainPublicationReader read. When the publications cannot be read,
 * the block says so and links to the full page: never an empty list, a total
 * of zero or "nothing found" (REQ-SHJ-005).
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-when-publications-cannot-be-read-the-page-says-so-req-shj-005
 */
class PlainPublicationBlocks {

	/**
	 * The endpoint both blocks read by default.
	 */
	public const ENDPOINT = '/index.php/apps/opencatalogi/api/federation/publications';

	private const MONTHS_NL = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];

	/**
	 * Constructor.
	 *
	 * @param PlainPublicationReader $reader     Reads the publications.
	 * @param PlainVocabulary        $vocabulary Names a Woo category.
	 */
	public function __construct(
		private readonly PlainPublicationReader $reader,
		private readonly PlainVocabulary $vocabulary,
	) {
	}//end __construct()

	/**
	 * The plain search: a GET form, the results, the total and the pages.
	 *
	 * @param array<string, mixed> $props  The widget's props.
	 * @param string               $route  The page's route.
	 * @param string               $query  The term, `_search`.
	 * @param int                  $page   The page, `_page`.
	 * @param PlainLinks           $links  The request's links.
	 * @param IL10N                $l10n   The document language.
	 * @param string               $locale The document language's tag.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
	 */
	public function search(array $props, string $route, string $query, int $page, PlainLinks $links, IL10N $l10n, string $locale): array {
		$pageSize    = min(50, max(1, (int)($props['pageSize'] ?? 10)));
		$detailRoute = '/'.trim((string)($props['detailRoute'] ?? '/publicatie'), '/');
		$page        = max(1, $page);
		$block       = [
			'kind'   => 'search',
			'form'   => [
				'action' => $links->plainBase(),
				'route'  => $route,
				'portal' => $links->portal(),
				'query'  => $query,
				'label'  => $l10n->t('Search publications'),
				'button' => $l10n->t('Search'),
			],
			'state'  => 'unavailable',
		];

		$answer = $this->reader->search(endpoint: $this->endpointOf(props: $props), query: $query, page: $page, pageSize: $pageSize);
		if ($answer['state'] !== 'ok') {
			$block['unavailable'] = $this->unavailable(href: $links->full(route: $route, query: $query, page: $page), l10n: $l10n);
			return $block;
		}

		$total   = $answer['total'];
		$pages   = max(1, (int)ceil($total / $pageSize));
		$results = [];
		foreach ($answer['results'] as $result) {
			$results[] = [
				'title'   => $result['title'],
				'href'    => ($result['id'] !== '') ? $links->plain(route: $detailRoute.'/'.$result['id']) : '',
				'date'    => $this->date(value: $result['date'], locale: $locale),
				'summary' => $result['summary'],
			];
		}

		$block['state']     = 'ok';
		$block['total']     = $total;
		$block['totalText'] = $l10n->n('%n result', '%n results', $total);
		$block['results']   = $results;
		$block['pageText']  = ($pages > 1) ? $l10n->t('Page %1$s of %2$s', [$page, $pages]) : '';
		$block['previous']  = ($page > 1) ? ['href' => $links->plain(route: $route, query: $query, page: $page - 1), 'text' => $l10n->t('Previous page')] : null;
		$block['next']      = ($page < $pages) ? ['href' => $links->plain(route: $route, query: $query, page: $page + 1), 'text' => $l10n->t('Next page')] : null;
		$block['navLabel']  = $l10n->t('Pages of results');

		return $block;
	}//end search()

	/**
	 * The plain detail of one publication; null when it does not exist or an
	 * anonymous visitor may not read it, so both answer the same 404.
	 *
	 * @param array<string, mixed> $props  The widget's props.
	 * @param string               $route  The route asked for, id included.
	 * @param string               $id     The publication id.
	 * @param PlainLinks           $links  The request's links.
	 * @param IL10N                $l10n   The document language.
	 * @param string               $locale The document language's tag.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
	 */
	public function detail(array $props, string $route, string $id, PlainLinks $links, IL10N $l10n, string $locale): ?array {
		$answer = $this->reader->publication(endpoint: $this->endpointOf(props: $props), id: $id);
		if ($answer['state'] === 'not-found') {
			return null;
		}

		if ($answer['state'] !== 'ok') {
			return ['kind' => 'publication', 'state' => 'unavailable', 'unavailable' => $this->unavailable(href: $links->full(route: $route), l10n: $l10n)];
		}

		$publication = (array)($answer['publication'] ?? []);
		$self        = (array)($publication['@self'] ?? []);
		$rows        = [];
		$date        = $this->date(value: (string)($publication['publicationDate'] ?? ''), locale: $locale);
		if ($date !== '') {
			$rows[] = ['label' => $l10n->t('Publication date'), 'values' => [$date]];
		}

		$category = trim((string)($publication['wooCategory'] ?? ''));
		if ($category !== '') {
			$rows[] = ['label' => $l10n->t('Information category'), 'values' => [$this->vocabulary->wooCategory(code: $category, locale: $locale)]];
		}

		$themes = (array)($answer['themes'] ?? []);
		if ($themes !== []) {
			$rows[] = ['label' => $l10n->t('Themes'), 'values' => $themes];
		}

		$documents = [];
		foreach ((array)($answer['documents'] ?? []) as $document) {
			$facts       = array_values(array_filter([$document['type'], $this->size(bytes: $document['size'])], static fn (string $fact): bool => $fact !== ''));
			$documents[] = [
				'name'  => $document['name'],
				'href'  => $document['href'],
				'facts' => implode(', ', $facts),
			];
		}

		return [
			'kind'             => 'publication',
			'state'            => 'ok',
			'title'            => (string)(($publication['name'] ?? '') !== '' ? $publication['name'] : ($self['name'] ?? ($self['title'] ?? $l10n->t('Untitled')))),
			'summary'          => $this->summary(publication: $publication),
			'rows'             => $rows,
			'documents'        => $documents,
			'documentsHeading' => $l10n->t('Documents'),
		];
	}//end detail()

	/**
	 * The sentence for publications that cannot be read here, with the way on.
	 *
	 * @param string $href The full page.
	 * @param IL10N  $l10n The document language.
	 *
	 * @return array{text: string, href: string, linkText: string}
	 */
	private function unavailable(string $href, IL10N $l10n): array {
		return [
			'text'     => $l10n->t('We cannot show the publications here right now.'),
			'href'     => $href,
			'linkText' => $l10n->t('Open the full page'),
		];
	}//end unavailable()

	/**
	 * The widget's endpoint, the default when it names none.
	 *
	 * @param array<string, mixed> $props The widget's props.
	 *
	 * @return string
	 */
	private function endpointOf(array $props): string {
		$endpoint = trim((string)($props['endpoint'] ?? ''));

		return ($endpoint !== '') ? $endpoint : self::ENDPOINT;
	}//end endpointOf()

	/**
	 * The summary under the title: `summary`, else `description`.
	 *
	 * @param array<string, mixed> $publication The publication.
	 *
	 * @return string
	 */
	private function summary(array $publication): string {
		foreach (['summary', 'description'] as $key) {
			if (is_string($publication[$key] ?? null) === true && trim($publication[$key]) !== '') {
				return trim($publication[$key]);
			}
		}

		return '';
	}//end summary()

	/**
	 * A date as the site writes it: `1 maart 2026`, `1 March 2026`; '' when unreadable.
	 *
	 * @param string $value  An ISO-8601 date or moment.
	 * @param string $locale The document language.
	 *
	 * @return string
	 */
	private function date(string $value, string $locale): string {
		if (trim($value) === '') {
			return '';
		}

		try {
			$date = new DateTimeImmutable($value);
		} catch (Throwable) {
			return '';
		}

		$date = $date->setTimezone(new \DateTimeZone('UTC'));
		if (str_starts_with($locale, 'en') === true) {
			return $date->format('j F Y');
		}

		return $date->format('j').' '.self::MONTHS_NL[(int)$date->format('n') - 1].' '.$date->format('Y');
	}//end date()

	/**
	 * A file size the way the site writes it: `120 kB`, `2,4 MB`; '' for none.
	 *
	 * @param int $bytes The size.
	 *
	 * @return string
	 */
	private function size(int $bytes): string {
		if ($bytes <= 0) {
			return '';
		}

		if ($bytes < 1000) {
			return $bytes.' B';
		}

		if ($bytes < 1000000) {
			return (string)round($bytes / 1000).' kB';
		}

		return str_replace('.', ',', number_format($bytes / 1000000, 1, '.', '')).' MB';
	}//end size()
}//end class
