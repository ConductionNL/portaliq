<?php

/**
 * Portaliq Page Route Claim (portal-cms-admin-ui)
 *
 * Decides whether a page being saved claims a route another page of the same
 * portal already holds in the same language. A translation of a page shares
 * its route in another locale, so only the same route in the same locale is a
 * duplicate.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\RowIdLookup;

/**
 * Finds the route a page would hold twice on its portal.
 *
 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
 */
class PageRouteClaim {

	/**
	 * The route the page claims that another page of the portal already holds
	 * in the same locale, or '' when there is none.
	 *
	 * @param array<string, mixed>             $page   The page being saved.
	 * @param string                           $pageId The page's own id or uuid ('' for a new page).
	 * @param array<int, array<string, mixed>> $others The portal's pages as stored, the page itself possibly among them.
	 *
	 * @return string The duplicated route, normalised.
	 *
	 * @spec openspec/changes/portal-cms-admin-ui/specs/portaliq-cms/spec.md#requirement-publishing-must-refuse-content-that-would-render-broken
	 */
	public function duplicate(array $page, string $pageId, array $others): string {
		$route = $this->route(route: $page['route'] ?? '');
		if ($route === '') {
			return '';
		}

		$locale = $this->locale(locale: $page['locale'] ?? '');
		$ids    = new RowIdLookup();
		foreach ($others as $other) {
			if (is_array($other) === false) {
				continue;
			}

			$otherId = (string)($ids->firstIdOrUuid(row: $other) ?? '');
			if ($pageId !== '' && $otherId === $pageId) {
				continue;
			}

			if ($this->route(route: $other['route'] ?? '') === $route
				&& $this->locale(locale: $other['locale'] ?? '') === $locale
			) {
				return $route;
			}
		}

		return '';
	}//end duplicate()

	/**
	 * A route as pages are keyed (as CmsPublishValidator keys them): a path
	 * with a leading slash, no query, no anchor, no trailing slash; '' for
	 * anything else.
	 *
	 * @param mixed $route The stored route.
	 *
	 * @return string
	 */
	private function route(mixed $route): string {
		if (is_string($route) === false) {
			return '';
		}

		$route = trim($route);
		if ($route === '' || str_starts_with($route, '/') === false || str_starts_with($route, '//') === true) {
			return '';
		}

		$route = (string)preg_replace('/[?#].*$/', '', $route);
		if ($route !== '/') {
			$route = rtrim($route, '/');
		}

		return $route;
	}//end route()

	/**
	 * A locale as compared here: trimmed and lower-case, '' for none.
	 *
	 * @param mixed $locale The stored locale.
	 *
	 * @return string
	 */
	private function locale(mixed $locale): string {
		if (is_string($locale) === false) {
			return '';
		}

		return strtolower(trim($locale));
	}//end locale()
}//end class
