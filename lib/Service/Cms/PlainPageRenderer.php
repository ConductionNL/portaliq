<?php

/**
 * The view model of the plain site page: what a visitor without JavaScript reads.
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PlainPageRendererInterface;
use OCA\Portaliq\Service\CmsReader;
use OCP\IL10N;

/**
 * Builds the plain version of one route of one portal: the portal's title,
 * its main menu as links into `/site/plain`, the page's title and summary, and
 * its body. A markdown body goes through `PlainMarkdown`; a grid body renders
 * per widget, in grid order, the text of the text widgets, the publication
 * search and detail, and for every other widget one sentence that it needs
 * JavaScript with its label and a link to the full page. No widget place is
 * left empty.
 *
 * Every read is `CmsReader` with the anonymous audience, whatever the request
 * carries: a server-rendered page must never put a resident's data into HTML
 * a shared cache could keep, and a draft answers as a route that does not
 * exist.
 *
 * A route with no page of its own falls back ONE level to its parent, which
 * then gets the trailing segment, exactly as `src/site/App.vue::parentRoute()`
 * does: `/publicatie/<id>` is the `/publicatie` page showing that id.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainPageRenderer implements PlainPageRendererInterface {

	/**
	 * The only audience this page reads as.
	 *
	 * @var string
	 */
	private const AUDIENCE = 'anonymous';

	/**
	 * The text widgets and what they render as.
	 *
	 * @var array<string, string>
	 */
	public const TEXT_WIDGETS = [
		'markdown' => 'html',
		'nlHeading' => 'heading',
		'nlParagraph' => 'paragraph',
		'nlList' => 'list',
		'nlLinkList' => 'links',
		'siteNavigation' => 'links',
	];

	/**
	 * The publication widgets, which render through the publication reader.
	 *
	 * @var array<int, string>
	 */
	public const PUBLICATION_WIDGETS = ['federatedSearch', 'publicationDetail'];

	/**
	 * Constructor.
	 *
	 * @param CmsReader              $reader   The public content read.
	 * @param IL10N                  $l10n     The words.
	 * @param PlainMarkdown          $markdown The markdown subset.
	 * @param PlainWidgetLabels      $labels   The widgets' names.
	 * @param PlainTextBlocks        $text     The text widgets.
	 * @param PlainPublicationSearch $search   The publication search.
	 * @param PlainPublicationDetail $detail   The publication detail.
	 */
	public function __construct(
		private readonly CmsReader $reader,
		private readonly IL10N $l10n,
		private readonly PlainMarkdown $markdown,
		private readonly PlainWidgetLabels $labels,
		private readonly PlainTextBlocks $text,
		private readonly PlainPublicationSearch $search,
		private readonly PlainPublicationDetail $detail,
	) {
	}//end __construct()

	/**
	 * The plain page of one route.
	 *
	 * @param array<string, mixed>|null $portal  The serving portal, or null.
	 * @param string                    $route   The route asked for.
	 * @param string                    $locale  The document language.
	 * @param string                    $query   The request's `_search`.
	 * @param int                       $page    The request's `_page`.
	 * @param PlainAddress              $address The addresses of this request.
	 *
	 * @return array<string, mixed> `status` (200 or 404), `portalTitle`, `menu`, `title`, `summary`, `blocks`, `fullUrl`.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function render(?array $portal, string $route, string $locale, string $query, int $page, PlainAddress $address): array {
		$route = '/' . trim($route, '/');
		$slug = (string)($portal['slug'] ?? '');
		$view = [
			'status' => 200,
			'portalTitle' => (string)($portal['title'] ?? ''),
			'homeUrl' => $address->plain(route: '/'),
			'menuLabel' => $this->l10n->t('Main menu'),
			'menu' => [],
			'fullUrl' => $address->full(route: $route, extra: $this->searchParams(query: $query, page: $page)),
			'fullLabel' => $this->l10n->t('Open the full page'),
			'plainNote' => $this->l10n->t('This is the plain version of this page.'),
		];
		if ($slug === '') {
			return $this->notFound(view: $view);
		}

		$view['menu'] = $this->menu(slug: $slug, locale: $locale, address: $address);
		[$found, $routeParam] = $this->pageFor(slug: $slug, route: $route, locale: $locale);
		if ($found === null) {
			return $this->notFound(view: $view);
		}

		$context = new PlainContext(
			route: $route,
			routeParam: $routeParam,
			query: $query,
			page: $page,
			address: $address,
			locale: $locale,
			menu: $view['menu']
		);
		$blocks = $this->blocks(page: $found, context: $context);
		foreach ($blocks as $block) {
			if (($block['kind'] ?? '') === 'publication' && ($block['state'] ?? '') === 'not-found') {
				// A missing and a withheld publication answer the same 404.
				$view['status'] = 404;
			}
		}

		return $view + [
			'title' => (string)($found['title'] ?? ''),
			'summary' => (string)($found['summary'] ?? ''),
			'blocks' => $blocks,
		];
	}//end render()

	/**
	 * The page at a route, else its parent with the trailing segment.
	 *
	 * @param string $slug   The portal.
	 * @param string $route  The route.
	 * @param string $locale The language.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string}
	 */
	private function pageFor(string $slug, string $route, string $locale): array {
		$page = $this->reader->page(portal: $slug, route: $route, locale: $locale, audience: self::AUDIENCE);
		if ($page !== null) {
			return [$page, ''];
		}

		$segments = array_values(array_filter(explode('/', $route), static fn (string $part): bool => $part !== ''));
		if (count($segments) < 2) {
			return [null, ''];
		}

		$parent = '/' . implode('/', array_slice($segments, 0, -1));
		$page = $this->reader->page(portal: $slug, route: $parent, locale: $locale, audience: self::AUDIENCE);

		return [$page, (string)end($segments)];
	}//end pageFor()

	/**
	 * The view of a route that has no published page: 404, the portal's
	 * not-found words, the menu kept.
	 *
	 * @param array<string, mixed> $view The view so far.
	 *
	 * @return array<string, mixed>
	 */
	private function notFound(array $view): array {
		return [
			'status' => 404,
			'title' => $this->l10n->t('Page not found'),
			'summary' => $this->l10n->t('This page does not exist (any more). Maybe the address was typed wrong, or we moved the page.'),
			'blocks' => [],
		] + $view;
	}//end notFound()

	/**
	 * The main menu (the menus at position 0) as plain links, children kept
	 * one level deep.
	 *
	 * @param string       $slug    The portal.
	 * @param string       $locale  The language.
	 * @param PlainAddress $address The addresses.
	 *
	 * @return list<array{label: string, href: string, items: list<array{label: string, href: string}>}>
	 */
	private function menu(string $slug, string $locale, PlainAddress $address): array {
		$links = [];
		foreach ($this->reader->menus(portal: $slug, locale: $locale, audience: self::AUDIENCE) as $menu) {
			if ((int)($menu['position'] ?? 0) !== 0) {
				continue;
			}

			foreach ((array)($menu['items'] ?? []) as $item) {
				$link = $this->menuLink(item: (array)$item, address: $address);
				if ($link === null) {
					continue;
				}

				$children = [];
				foreach ((array)($item['items'] ?? []) as $child) {
					$childLink = $this->menuLink(item: (array)$child, address: $address);
					if ($childLink !== null) {
						$children[] = $childLink;
					}
				}

				$links[] = $link + ['items' => $children];
			}
		}

		return $links;
	}//end menu()

	/**
	 * One menu entry as a link, or null when it has no name or no address.
	 *
	 * @param array<string, mixed> $item    The entry.
	 * @param PlainAddress         $address The addresses.
	 *
	 * @return array{label: string, href: string}|null
	 */
	private function menuLink(array $item, PlainAddress $address): ?array {
		$label = trim((string)($item['name'] ?? ''));
		$href = $address->authored(link: (string)($item['link'] ?? ''));
		if ($label === '' || $href === '') {
			return null;
		}

		return ['label' => $label, 'href' => $href];
	}//end menuLink()

	/**
	 * The body as blocks.
	 *
	 * @param array<string, mixed> $page    The published page.
	 * @param PlainContext         $context The request.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function blocks(array $page, PlainContext $context): array {
		$body = (array)($page['body'] ?? []);
		if ((string)($body['type'] ?? 'markdown') === 'markdown') {
			$html = $this->markdown->render(markdown: (string)($body['markdown'] ?? ''));
			if ($html === '') {
				return [];
			}

			return [['kind' => 'html', 'html' => $html]];
		}

		$blocks = [];
		foreach ((array)($body['widgets'] ?? []) as $widget) {
			if (is_array($widget) === true) {
				$blocks[] = $this->widget(widget: $widget, context: $context);
			}
		}

		return $blocks;
	}//end blocks()

	/**
	 * One widget as a block. Never nothing: a widget that renders no text
	 * here says it needs JavaScript.
	 *
	 * @param array<string, mixed> $widget  The placement.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function widget(array $widget, PlainContext $context): array {
		$key = (string)($widget['widgetKey'] ?? '');
		$props = (array)($widget['props'] ?? []);
		$block = match ($key) {
			'markdown' => $this->text->markdownBlock(props: $props),
			'nlHeading' => $this->text->headingBlock(props: $props),
			'nlParagraph' => $this->text->paragraphBlock(props: $props),
			'nlList' => $this->text->listBlock(props: $props),
			'nlLinkList' => $this->text->linksBlock(props: $props, address: $context->address),
			'siteNavigation' => $this->navigationBlock(context: $context),
			'federatedSearch' => $this->search->build(
				props: $props,
				route: $context->route,
				query: $context->query,
				page: $context->page,
				address: $context->address,
				locale: $context->locale
			),
			'publicationDetail' => $this->detailBlock(props: $props, context: $context),
			default => null,
		};

		if ($block === null) {
			return $this->needsJavascript(key: $key, context: $context);
		}

		return $block;
	}//end widget()

	/**
	 * The sentence for a widget that needs JavaScript.
	 *
	 * @param string       $key     The widget key.
	 * @param PlainContext $context The request.
	 *
	 * @return array<string, mixed>
	 */
	private function needsJavascript(string $key, PlainContext $context): array {
		$label = $this->labels->label(key: $key);

		return [
			'kind' => 'needsJs',
			'label' => $label,
			'sentence' => $this->l10n->t('This part of the page, "%s", needs JavaScript.', [$label]),
			'fullUrl' => $context->fullUrl(),
			'fullLabel' => $this->l10n->t('Open the full page'),
		];
	}//end needsJavascript()

	/**
	 * The menu block: the portal's main menu, the same links the page's own
	 * header shows, children after their parent.
	 *
	 * @param PlainContext $context The request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function navigationBlock(PlainContext $context): ?array {
		$links = [];
		foreach ($context->menu as $item) {
			$links[] = ['label' => $item['label'], 'href' => $item['href'], 'description' => ''];
			foreach ($item['items'] as $child) {
				$links[] = ['label' => $child['label'], 'href' => $child['href'], 'description' => ''];
			}
		}

		if ($links === []) {
			return null;
		}

		return ['kind' => 'links', 'heading' => '', 'intro' => '', 'links' => $links];
	}//end navigationBlock()

	/**
	 * The publication detail widget, for the route's trailing segment. On the
	 * detail page itself, with no id, there is nothing to read and the widget
	 * says it needs JavaScript.
	 *
	 * @param array<string, mixed> $props   The props.
	 * @param PlainContext         $context The request.
	 *
	 * @return array<string, mixed>|null
	 */
	private function detailBlock(array $props, PlainContext $context): ?array {
		if ($context->routeParam === '') {
			return null;
		}

		return $this->detail->build(props: $props, id: $context->routeParam, fullUrl: $context->fullUrl(), locale: $context->locale);
	}//end detailBlock()

	/**
	 * `_search` and `_page` of an address; page 1 left out.
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

}//end class
