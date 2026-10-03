<?php

/**
 * Portaliq Traffic Page Path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-a-pages-traffic-must-be-counted-by-its-in-site-route
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

/**
 * The in-site route a page view belongs to, so a page and its traffic
 * meet on one string.
 *
 * WHY NOT THE STORED PATH. The built-in site keeps a page's route in the
 * `route` query parameter (`src/site/App.vue`) and the collector stores
 * the URL path with the whole query stripped (`ReferrerClassifier::path`).
 * Every page of the built-in site was therefore stored as one path, the
 * renderer's. The route rule here is the one the traffic client already
 * applies to experiments (`siteRoute` in `src/traffic/helpers.js`): the
 * `route` parameter when the location carries one, else the path, and no
 * trailing slash. Pure.
 *
 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-a-pages-traffic-must-be-counted-by-its-in-site-route
 */
class TrafficPagePath {

	/**
	 * The longest route kept, like the stored path.
	 */
	private const MAX = 512;

	/**
	 * The built-in site renderer's path (`portalPage#site` in
	 * appinfo/routes.php), with or without `index.php`.
	 */
	private const SITE_RENDERER = '#/apps/portaliq/site/?$#';

	/**
	 * The in-site route of a stored event.
	 *
	 * @param array<string, mixed> $event The stored event.
	 *
	 * @return string The route; the stored path when the location names no route.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-a-pages-traffic-must-be-counted-by-its-in-site-route
	 */
	public function ofEvent(array $event): string {
		$location = (string)($event['pageLocation'] ?? '');
		$route = $this->routeParameter(location: $location);
		if ($route !== null) {
			return $this->route(value: $route);
		}

		$path = trim((string)($event['pagePath'] ?? ''));
		if ($path === '') {
			$fromLocation = parse_url($location, PHP_URL_PATH);
			$path = '/';
			if (is_string($fromLocation) === true && $fromLocation !== '') {
				$path = $fromLocation;
			}
		}

		// The built-in site with no route parameter is its home page: that
		// is what `routeFromLocation` in src/site/App.vue renders there.
		if (preg_match(self::SITE_RENDERER, $path) === 1) {
			return '/';
		}

		return $this->trimTrailing(path: $path);
	}

	/**
	 * A page's `route` as the rows key it: a leading slash, no trailing
	 * one, the home page `/`.
	 *
	 * @param string $value The route.
	 *
	 * @return string The normalised route.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-a-pages-traffic-must-be-counted-by-its-in-site-route
	 */
	public function route(string $value): string {
		$route = trim($value);
		if (str_starts_with($route, '/') === false) {
			$route = '/' . $route;
		}

		return $this->trimTrailing(path: $route);
	}

	/**
	 * The raw `route` query parameter of a location, or null when it has
	 * none. Read the way the client reads it: the first `route=` pair of
	 * the query, percent-decoded, an empty value counting as none.
	 *
	 * @param string $location The page location.
	 *
	 * @return string|null The route.
	 */
	private function routeParameter(string $location): ?string {
		$query = parse_url($location, PHP_URL_QUERY);
		if (is_string($query) === false || $query === '') {
			return null;
		}

		foreach (explode('&', $query) as $pair) {
			if (str_starts_with($pair, 'route=') === false) {
				continue;
			}

			$value = rawurldecode(substr($pair, 6));
			if ($value === '') {
				return null;
			}

			return $value;
		}

		return null;
	}

	/**
	 * Drop a trailing slash (not the root's) and bound the length.
	 *
	 * @param string $path The path.
	 *
	 * @return string The path.
	 */
	private function trimTrailing(string $path): string {
		if (strlen($path) > 1) {
			$path = rtrim($path, '/');
		}

		if ($path === '') {
			$path = '/';
		}

		return mb_substr($path, 0, self::MAX);
	}
}
