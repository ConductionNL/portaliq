<?php

/**
 * The document head of one public site page, rendered by the server.
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
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\CmsReader;

/**
 * Title, description, robots, canonical and Open Graph image for a route.
 *
 * Read through `CmsReader::page()` with the anonymous audience, the same read
 * the public content API makes, so a draft never lends its title to the head.
 * A route with no published page gets the portal's title and `noindex`: a
 * crawler must not index a page that does not exist.
 */
class SiteHead {

	/**
	 * Constructor.
	 *
	 * @param CmsReader $reader The public page read.
	 */
	public function __construct(
		private readonly CmsReader $reader,
	) {
	}//end __construct()

	/**
	 * The head for one route of one portal.
	 *
	 * @param array<string, mixed>|null $portal    The serving portal, or null.
	 * @param string                    $route     The route asked for, e.g. `/contact`.
	 * @param string                    $locale    The document language.
	 * @param string                    $canonical The page's absolute address.
	 *
	 * @return array{title: string, description: string, robots: string, canonical: string, ogImage: string}
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function for(?array $portal, string $route, string $locale, string $canonical): array {
		$portalTitle = (string)($portal['title'] ?? '');
		$head = ['title' => $portalTitle, 'description' => '', 'robots' => 'noindex', 'canonical' => '', 'ogImage' => ''];

		$slug = (string)($portal['slug'] ?? '');
		if ($slug === '') {
			return $head;
		}

		$locales = array_values((array)($portal['locales'] ?? []));
		$page    = $this->reader->page(
			portal: $slug,
			route: $this->normalise(route: $route),
			locale: $locale,
			audience: 'anonymous',
			defaultLocale: (string)($locales[0] ?? '')
		);
		if ($page === null) {
			return $head;
		}

		$seo = (array)($page['seo'] ?? []);

		return [
			'title' => $this->titleOf(page: $page, portalTitle: $portalTitle),
			'description' => $this->firstFilled(first: $seo['description'] ?? '', second: $page['summary'] ?? ''),
			'robots' => $this->robotsOf(seo: $seo),
			'canonical' => $canonical,
			'ogImage' => $this->imageUrl(value: (string)($seo['image'] ?? '')),
		];
	}//end for()

	/**
	 * The page's search title, or its title, followed by the portal's name.
	 *
	 * @param array<string, mixed> $page        The published page.
	 * @param string               $portalTitle The portal's name.
	 *
	 * @return string
	 */
	private function titleOf(array $page, string $portalTitle): string {
		$title = $this->firstFilled(first: ($page['seo']['title'] ?? ''), second: $page['title'] ?? '');
		if ($title === '' || $title === $portalTitle) {
			return $portalTitle;
		}

		if ($portalTitle === '') {
			return $title;
		}

		return $title . ' - ' . $portalTitle;
	}//end titleOf()

	/**
	 * The robots value a page asks for.
	 *
	 * @param array<string, mixed> $seo The page's search fields.
	 *
	 * @return string
	 */
	private function robotsOf(array $seo): string {
		if (($seo['noindex'] ?? false) === true) {
			return 'noindex';
		}

		return 'index, follow';
	}//end robotsOf()

	/**
	 * The first of two values that is not blank, trimmed.
	 *
	 * @param mixed $first  The preferred value.
	 * @param mixed $second The fallback.
	 *
	 * @return string
	 */
	private function firstFilled(mixed $first, mixed $second): string {
		$first = trim((string)$first);
		if ($first !== '') {
			return $first;
		}

		return trim((string)$second);
	}//end firstFilled()

	/**
	 * A route as pages store it: leading slash, no trailing one, `/` for home.
	 *
	 * @param string $route The route asked for.
	 *
	 * @return string
	 */
	private function normalise(string $route): string {
		$route = '/' . trim($route, '/');

		return $route;
	}//end normalise()

	/**
	 * An absolute http(s) image address, or '' for anything else.
	 *
	 * @param string $value The stored value.
	 *
	 * @return string
	 */
	private function imageUrl(string $value): string {
		$value = trim($value);
		if (preg_match('#^https?://#i', $value) === 1) {
			return $value;
		}

		return '';
	}//end imageUrl()
}//end class
