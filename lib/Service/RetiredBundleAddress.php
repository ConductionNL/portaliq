<?php

/**
 * Where a path of a retired bundled site lives on the authored site.
 *
 * A bundled single-page site answered on its own paths (`/woo/zoeken`,
 * `/woo/publicatie/<id>`, `/woo/mijn-omgeving`). Once the same surface is
 * authored as portal content, each of those paths must keep resolving: a
 * public page to the site page of the same route, the own area and the
 * bundle's sign-in screens to the site's own area (which carries the sign-in
 * page), and the registration forms and administration screens, which are not
 * carried over, to the site's home. Nothing a visitor bookmarked 404s.
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Maps a path of a retired bundled site to a route of the authored site.
 *
 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
 */
final class RetiredBundleAddress {

	/**
	 * The site's own area: the resident's home, and the sign-in page for a
	 * visitor who is not signed in.
	 */
	public const OWN_AREA = '/mijn';

	/**
	 * The site's home.
	 */
	public const HOME = '/';

	/**
	 * Paths of the bundle whose surface moved to the own area: its own-area
	 * screens and its sign-in, registration, reminder, authorisation and
	 * sign-out screens, which the site never reproduces.
	 *
	 * @var list<string>
	 */
	private const TO_OWN_AREA = [
		'/mijn-omgeving',
		'/mijn-account',
		'/login',
		'/register',
		'/reminder',
		'/authorization',
		'/logout',
	];

	/**
	 * Path prefixes of the bundle that are not carried over to the site: the
	 * registration forms and the administration screens. They land on the
	 * site's home rather than on a not-found page.
	 *
	 * @var list<string>
	 */
	private const NOT_CARRIED_OVER = [
		'/forms',
		'/beheer',
	];

	/**
	 * The site route a path of the retired bundle resolves to.
	 *
	 * A public page keeps its route, because the authored site has a page of
	 * the same route (`/zoeken`, `/onderwerpen`) or resolves a deeper path to
	 * its parent page with the rest as the parameter (`/publicatie/<id>`). A
	 * path the bundle did not know keeps its route too, so the site answers it
	 * with its own not-found page.
	 *
	 * @param string $path The path under the bundle, with or without a leading slash.
	 *
	 * @return string The site route, always starting with a slash.
	 *
	 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
	 */
	public function siteRoute(string $path): string {
		$route = $this->normalised(path: $path);

		foreach (self::TO_OWN_AREA as $moved) {
			if ($this->isUnder(route: $route, base: $moved) === true) {
				return self::OWN_AREA;
			}
		}

		foreach (self::NOT_CARRIED_OVER as $retired) {
			if ($this->isUnder(route: $route, base: $retired) === true) {
				return self::HOME;
			}
		}

		return $route;
	}//end siteRoute()

	/**
	 * The path as a route: one leading slash, no trailing slash, no empty or
	 * dot segments, so `../` cannot climb out of the site.
	 *
	 * @param string $path The path.
	 *
	 * @return string The route.
	 *
	 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
	 */
	private function normalised(string $path): string {
		$segments = array_filter(
			explode('/', str_replace('\\', '/', $path)),
			static fn (string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..'
		);

		return '/' . implode('/', $segments);
	}//end normalised()

	/**
	 * Whether a route is a base route or lies under it (`/forms` and
	 * `/forms/dienst`, never `/formsx`).
	 *
	 * @param string $route The route.
	 * @param string $base  The base route.
	 *
	 * @return bool Whether it is.
	 *
	 * @spec openspec/changes/retire-the-vendored-woo-bundle/specs/portaliq-cms/spec.md#requirement-the-authored-portal-must-answer-on-the-paths-the-bundle-answered-on
	 */
	private function isUnder(string $route, string $base): bool {
		return $route === $base || str_starts_with($route, $base . '/') === true;
	}//end isUnder()
}//end class
