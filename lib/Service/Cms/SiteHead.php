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
 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
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
	 * @spec openspec/changes/site-page-seo-history-and-media/specs/site-page-seo-history-and-media/spec.md
	 */
	public function for(?array $portal, string $route, string $locale, string $canonical): array {
		$portalTitle = (string)($portal['title'] ?? '');
		$head = ['title' => $portalTitle, 'description' => '', 'robots' => 'noindex', 'canonical' => '', 'ogImage' => ''];

		$slug = (string)($portal['slug'] ?? '');
		if ($slug === '') {
			return $head;
		}

		$page = $this->reader->page(portal: $slug, route: $this->normalise(route: $route), locale: $locale, audience: 'anonymous');
		if ($page === null) {
			return $head;
		}

		$seo = (array)($page['seo'] ?? []);
		$title = trim((string)($seo['title'] ?? ''));
		if ($title === '') {
			$title = trim((string)($page['title'] ?? ''));
		}

		if ($title !== '' && $portalTitle !== '' && $title !== $portalTitle) {
			$title = $title . ' - ' . $portalTitle;
		}

		$description = trim((string)($seo['description'] ?? ''));
		if ($description === '') {
			$description = trim((string)($page['summary'] ?? ''));
		}

		return [
			'title' => ($title !== '' ? $title : $portalTitle),
			'description' => $description,
			'robots' => (($seo['noindex'] ?? false) === true ? 'noindex' : 'index, follow'),
			'canonical' => $canonical,
			'ogImage' => $this->imageUrl(value: (string)($seo['image'] ?? ($page['heroImage'] ?? ''))),
		];
	}//end for()

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
