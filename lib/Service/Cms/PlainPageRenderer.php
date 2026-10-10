<?php

/**
 * Portaliq plain page renderer
 *
 * Builds the plain version of a site page, which the server renders for a
 * visitor without JavaScript.
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
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\AppInfo\Application;
use OCA\Portaliq\Service\CmsReader;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * The view model of the plain page: the portal's title and main menu, the
 * page's title, summary and body, and per grid widget its text, its
 * publication block, or one sentence saying the part needs JavaScript.
 *
 * Headless is kept (ADR-086): every read is `CmsReader` with the anonymous
 * audience, the same read the public content API makes, whatever session
 * the request carries. A draft or a missing route reads as null, so both
 * answer the same 404.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainPageRenderer {

	/**
	 * The audience of every read: never the session's.
	 */
	public const AUDIENCE = 'anonymous';

	/**
	 * The widgets rendered as text.
	 */
	public const TEXT_WIDGETS = ['markdown', 'nlHeading', 'nlParagraph', 'nlList', 'nlLinkList'];

	/**
	 * The widgets rendered from opencatalogi's publications.
	 */
	public const PUBLICATION_WIDGETS = ['federatedSearch', 'publicationDetail'];

	/**
	 * Constructor.
	 *
	 * @param CmsReader              $reader       Reads pages and menus as an anonymous visitor.
	 * @param PlainMarkdown          $markdown     Renders a markdown body.
	 * @param PlainPublicationBlocks $publications Renders the publication widgets.
	 * @param PlainVocabulary        $vocabulary   Names a widget.
	 * @param IURLGenerator          $urlGenerator Builds the links.
	 * @param IFactory               $l10nFactory  The document language's words.
	 */
	public function __construct(
		private readonly CmsReader $reader,
		private readonly PlainMarkdown $markdown,
		private readonly PlainPublicationBlocks $publications,
		private readonly PlainVocabulary $vocabulary,
		private readonly IURLGenerator $urlGenerator,
		private readonly IFactory $l10nFactory,
	) {
	}//end __construct()

	/**
	 * The notice every site page carries for a visitor without JavaScript
	 * (REQ-SHJ-001), in the document language.
	 *
	 * @param string $locale The document language.
	 *
	 * @return array{text: string, linkText: string}
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-every-site-page-says-so-when-javascript-is-off-req-shj-001
	 */
	public function notice(string $locale): array {
		$l10n = $this->l10nFactory->get(Application::APP_ID, $locale);

		return [
			'text'     => $l10n->t('This website uses JavaScript for the parts where you do something.'),
			'linkText' => $l10n->t('Read the plain version of this page'),
		];
	}//end notice()

	/**
	 * The plain page of one route.
	 *
	 * @param array<string, mixed>|null $portal      The serving portal, or null.
	 * @param string                    $route       The route asked for.
	 * @param string                    $locale      The document language.
	 * @param string                    $portalParam The portal the visitor named, or ''.
	 * @param string                    $query       The search term, `_search`.
	 * @param int                       $page        The results page, `_page`.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	public function render(?array $portal, string $route, string $locale, string $portalParam, string $query, int $page): array {
		$route = '/'.trim($route, '/');
		$l10n  = $this->l10nFactory->get(Application::APP_ID, $locale);
		$links = new PlainLinks(urlGenerator: $this->urlGenerator, portal: $portalParam);
		$slug  = (string)($portal['slug'] ?? '');

		$view = [
			'status'        => 200,
			'portalTitle'   => (string)($portal['title'] ?? ''),
			'menu'          => ($slug !== '') ? $this->menu(slug: $slug, locale: $locale, links: $links) : [],
			'menuLabel'     => $l10n->t('Main menu'),
			'fullUrl'       => $links->full(route: $route, query: $query, page: $page),
			'fullLinkText'  => $l10n->t('Open this page with JavaScript'),
			'canonical'     => $links->canonical(route: $route),
			'homeUrl'       => $links->plain(route: '/'),
			'title'         => '',
			'summary'       => '',
			'blocks'        => [],
		];

		[$pageRow, $routeParam] = $this->pageAt(slug: $slug, route: $route, locale: $locale);
		if ($pageRow === null) {
			return $this->notFound(view: $view, l10n: $l10n);
		}

		$view['title']   = (string)($pageRow['title'] ?? '');
		$view['summary'] = (string)($pageRow['summary'] ?? '');

		$body = (array)($pageRow['body'] ?? []);
		if ((string)($body['type'] ?? 'markdown') === 'markdown') {
			$view['blocks'][] = ['kind' => 'html', 'html' => $this->markdown->toHtml(markdown: (string)($body['markdown'] ?? ''))];
			return $view;
		}

		$context = ['route' => $route, 'routeParam' => $routeParam, 'query' => $query, 'page' => $page, 'locale' => $locale];
		foreach ((array)($body['widgets'] ?? []) as $widget) {
			if (is_array($widget) === false) {
				continue;
			}

			$block = $this->block(widget: $widget, context: $context, links: $links, l10n: $l10n);
			if ($block === null) {
				return $this->notFound(view: $view, l10n: $l10n);
			}

			$view['blocks'][] = $block;
		}

		return $view;
	}//end render()

	/**
	 * The page at a route, else its parent's with the last segment as the
	 * route parameter, the way the site resolves `/publicatie/<id>`
	 * (`src/site/App.vue` parentRoute()).
	 *
	 * @param string $slug   The portal slug.
	 * @param string $route  The route.
	 * @param string $locale The document language.
	 *
	 * @return array{0: array<string, mixed>|null, 1: string}
	 */
	private function pageAt(string $slug, string $route, string $locale): array {
		if ($slug === '') {
			return [null, ''];
		}

		$page = $this->reader->page(portal: $slug, route: $route, locale: $locale, audience: self::AUDIENCE);
		if ($page !== null) {
			return [$page, ''];
		}

		$segments = array_values(array_filter(explode('/', $route), static fn (string $part): bool => $part !== ''));
		if (count($segments) < 2) {
			return [null, ''];
		}

		$parent = '/'.implode('/', array_slice($segments, 0, -1));
		$page   = $this->reader->page(portal: $slug, route: $parent, locale: $locale, audience: self::AUDIENCE);

		return [$page, (string)end($segments)];
	}//end pageAt()

	/**
	 * One grid widget as a block; null when the widget answers 404 (a
	 * publication that does not exist or may not be read).
	 *
	 * @param array<string, mixed>                                                                       $widget  The widget.
	 * @param array{route: string, routeParam: string, query: string, page: int, locale: string}         $context The request.
	 * @param PlainLinks                                                                                 $links   The links.
	 * @param IL10N                                                                                      $l10n    The words.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	private function block(array $widget, array $context, PlainLinks $links, IL10N $l10n): ?array {
		$key   = (string)($widget['widgetKey'] ?? '');
		$props = (array)($widget['props'] ?? []);

		if ($key === 'federatedSearch') {
			return $this->publications->search(props: $props, route: $context['route'], query: $context['query'], page: $context['page'], links: $links, l10n: $l10n, locale: $context['locale']);
		}

		if ($key === 'publicationDetail' && $context['routeParam'] !== '') {
			return $this->publications->detail(props: $props, route: $context['route'], id: $context['routeParam'], links: $links, l10n: $l10n, locale: $context['locale']);
		}

		$html = $this->text(key: $key, props: $props, links: $links);
		if ($html !== null) {
			return ['kind' => 'html', 'html' => $html];
		}

		return [
			'kind'     => 'needsJs',
			'text'     => $l10n->t('%s: this part only works with JavaScript.', [$this->vocabulary->widgetLabel(key: $key)]),
			'href'     => $links->full(route: $context['route']),
			'linkText' => $l10n->t('Open the full page'),
		];
	}//end block()

	/**
	 * A text widget as safe HTML, read from the props its component reads;
	 * null for a widget that is not a text widget.
	 *
	 * @param string               $key   The widget key.
	 * @param array<string, mixed> $props The props.
	 * @param PlainLinks           $links The links.
	 *
	 * @return string|null
	 */
	private function text(string $key, array $props, PlainLinks $links): ?string {
		return match ($key) {
			'markdown' => $this->markdown->toHtml(markdown: (string)($props['markdown'] ?? ($props['source'] ?? ''))),
			'nlHeading' => $this->heading(props: $props),
			'nlParagraph' => '<p>'.$this->escapeLines(text: (string)($props['text'] ?? '')).'</p>',
			'nlList' => $this->listHtml(props: $props),
			'nlLinkList' => $this->linkList(props: $props, links: $links),
			default => null,
		};
	}//end text()

	/**
	 * `nlHeading`: `text` at `level`, clamped to 1 to 6.
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return string
	 */
	private function heading(array $props): string {
		$level = min(6, max(1, (int)($props['level'] ?? 2)));

		return '<h'.$level.'>'.$this->escape(text: (string)($props['text'] ?? '')).'</h'.$level.'>';
	}//end heading()

	/**
	 * `nlList`: `items` (a string, or `{title, text}`), `ordered` or the
	 * `numbered`/`steps` display as a numbered list.
	 *
	 * @param array<string, mixed> $props The props.
	 *
	 * @return string
	 */
	private function listHtml(array $props): string {
		$tag   = (($props['ordered'] ?? false) === true || in_array((string)($props['display'] ?? ''), ['numbered', 'steps'], true) === true) ? 'ol' : 'ul';
		$items = '';
		foreach ((array)($props['items'] ?? []) as $item) {
			$title = trim((string)(is_array($item) === true ? ($item['title'] ?? '') : $item));
			if ($title === '') {
				continue;
			}

			$text   = trim((string)(is_array($item) === true ? ($item['text'] ?? '') : ''));
			$items .= '<li>'.$this->escape(text: $title).(($text !== '') ? ' '.$this->escape(text: $text) : '').'</li>';
		}

		return '<'.$tag.'>'.$items.'</'.$tag.'>';
	}//end listHtml()

	/**
	 * `nlLinkList`: `heading`, `intro` and `links` (`{label, href, description}`);
	 * a route opens its plain page, http(s) and mailto stay, anything else is
	 * left out like the component leaves it out.
	 *
	 * @param array<string, mixed> $props The props.
	 * @param PlainLinks           $links The links.
	 *
	 * @return string
	 */
	private function linkList(array $props, PlainLinks $links): string {
		$html    = '';
		$heading = trim((string)($props['heading'] ?? ''));
		if ($heading !== '') {
			$html .= '<h2>'.$this->escape(text: $heading).'</h2>';
		}

		$intro = trim((string)($props['intro'] ?? ''));
		if ($intro !== '') {
			$html .= '<p>'.$this->escape(text: $intro).'</p>';
		}

		$items = '';
		foreach ((array)($props['links'] ?? []) as $link) {
			$href = trim((string)(is_array($link) === true ? ($link['href'] ?? '') : ''));
			$href = $this->linkTarget(href: $href, links: $links);
			if ($href === '') {
				continue;
			}

			$label       = trim((string)(($link['label'] ?? '') !== '' ? $link['label'] : $link['href']));
			$description = trim((string)($link['description'] ?? ''));
			$items      .= '<li><a href="'.$this->escape(text: $href).'">'.$this->escape(text: $label).'</a>'.(($description !== '') ? ' '.$this->escape(text: $description) : '').'</li>';
		}

		return $html.'<ul>'.$items.'</ul>';
	}//end linkList()

	/**
	 * Where an authored link goes on the plain page: a route to its plain
	 * page; http, https and mailto as written; '' for anything else.
	 *
	 * @param string     $href  The authored target.
	 * @param PlainLinks $links The links.
	 *
	 * @return string
	 */
	private function linkTarget(string $href, PlainLinks $links): string {
		if (preg_match('#^/(?![/\\\\])#', $href) === 1) {
			return $links->plain(route: (string)strtok($href, '?#'));
		}

		if (preg_match('#^(https?:|mailto:)#i', $href) === 1 && $this->markdown->isSafeTarget(href: $href) === true) {
			return $href;
		}

		return '';
	}//end linkTarget()

	/**
	 * The portal's main menu (position 0) as links; a route opens its plain page.
	 *
	 * @param string     $slug   The portal slug.
	 * @param string     $locale The document language.
	 * @param PlainLinks $links  The links.
	 *
	 * @return list<array{name: string, href: string}>
	 */
	private function menu(string $slug, string $locale, PlainLinks $links): array {
		$items = [];
		foreach ($this->reader->menus(portal: $slug, locale: $locale, audience: self::AUDIENCE) as $menu) {
			if ((int)($menu['position'] ?? 0) !== 0) {
				continue;
			}

			foreach ((array)($menu['items'] ?? []) as $item) {
				$href = $this->linkTarget(href: trim((string)($item['link'] ?? '')), links: $links);
				$name = trim((string)($item['name'] ?? ''));
				if ($href !== '' && $name !== '') {
					$items[] = ['name' => $name, 'href' => $href];
				}
			}
		}

		return $items;
	}//end menu()

	/**
	 * The view as the portal's not-found page, status 404.
	 *
	 * @param array<string, mixed> $view The view so far.
	 * @param IL10N                $l10n The words.
	 *
	 * @return array<string, mixed>
	 */
	private function notFound(array $view, IL10N $l10n): array {
		$view['status']    = 404;
		$view['title']     = $l10n->t('Page not found');
		$view['summary']   = '';
		$view['canonical'] = '';
		$view['blocks']  = [
			['kind' => 'html', 'html' => '<p>'.$this->escape(text: $l10n->t('This page does not exist (any more). Maybe the address was typed wrong, or we moved the page.')).'</p>'],
		];

		return $view;
	}//end notFound()

	/**
	 * Text with its line breaks kept, escaped.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function escapeLines(string $text): string {
		return nl2br($this->escape(text: trim($text)), false);
	}//end escapeLines()

	/**
	 * HTML-escape text.
	 *
	 * @param string $text The text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}//end escape()
}//end class
