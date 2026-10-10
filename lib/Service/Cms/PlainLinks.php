<?php

/**
 * Portaliq plain links
 *
 * The addresses the plain version of a site page links to.
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
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-every-site-page-says-so-when-javascript-is-off-req-shj-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCP\IURLGenerator;

/**
 * One request's links: the plain page and the full page of a route, both
 * keeping the portal the visitor named (`?portal=`), and the parameters the
 * search block uses (`_search`, `_page`).
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-every-site-page-says-so-when-javascript-is-off-req-shj-001
 */
class PlainLinks {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator Builds the addresses.
	 * @param string        $portal       The portal the visitor named, or ''.
	 */
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly string $portal,
	) {
	}//end __construct()

	/**
	 * The plain page of a route.
	 *
	 * @param string $route The in-site route.
	 * @param string $query The search term, or ''.
	 * @param int    $page  The results page; 1 is left out.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-every-site-page-says-so-when-javascript-is-off-req-shj-001
	 */
	public function plain(string $route, string $query='', int $page=1): string {
		return $this->urlGenerator->linkToRoute('portaliq.portalPage.plain', $this->params(route: $route, query: $query, page: $page));
	}//end plain()

	/**
	 * The full page of a route, the one that runs JavaScript.
	 *
	 * @param string $route The in-site route.
	 * @param string $query The search term, or ''.
	 * @param int    $page  The results page; 1 is left out.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	public function full(string $route, string $query='', int $page=1): string {
		return $this->urlGenerator->linkToRoute('portaliq.portalPage.site', $this->params(route: $route, query: $query, page: $page));
	}//end full()

	/**
	 * The absolute full page of a route, for `<link rel="canonical">`.
	 *
	 * @param string $route The in-site route.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	public function canonical(string $route): string {
		return $this->urlGenerator->linkToRouteAbsolute('portaliq.portalPage.site', $this->params(route: $route, query: '', page: 1));
	}//end canonical()

	/**
	 * The plain page's own address without parameters, the search form's action.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
	 */
	public function plainBase(): string {
		return $this->urlGenerator->linkToRoute('portaliq.portalPage.plain');
	}//end plainBase()

	/**
	 * The portal the visitor named, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-publications-can-be-searched-without-javascript-req-shj-003
	 */
	public function portal(): string {
		return $this->portal;
	}//end portal()

	/**
	 * The query parameters, in the order the site writes them.
	 *
	 * @param string $route The route.
	 * @param string $query The term.
	 * @param int    $page  The page.
	 *
	 * @return array<string, string>
	 */
	private function params(string $route, string $query, int $page): array {
		$params = ['route' => $route];
		if ($this->portal !== '') {
			$params['portal'] = $this->portal;
		}

		if ($query !== '') {
			$params['_search'] = $query;
		}

		if ($page > 1) {
			$params['_page'] = (string)$page;
		}

		return $params;
	}//end params()
}//end class
