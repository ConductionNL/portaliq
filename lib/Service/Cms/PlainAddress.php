<?php

/**
 * The addresses the plain site page links to.
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

/**
 * Builds the two addresses of a route: its plain version (`/site/plain`) and
 * its full page (`/site`), both naming the portal the request named. A `/` in
 * a value stays readable, every other reserved character is encoded, the same
 * way `SiteShell::plainUrl()` builds the notice's link.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainAddress {

	/**
	 * Constructor.
	 *
	 * @param string $siteBase   The site's address, e.g. `/index.php/apps/portaliq/site`.
	 * @param string $portalSlug The portal the request named, or '' (the host decides).
	 */
	public function __construct(
		private readonly string $siteBase,
		private readonly string $portalSlug,
	) {
	}//end __construct()

	/**
	 * The address the plain search form submits to.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
	 */
	public function plainBase(): string {
		return $this->siteBase . '/plain';
	}//end plainBase()

	/**
	 * The portal the request named, or ''.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.2
	 */
	public function portalSlug(): string {
		return $this->portalSlug;
	}//end portalSlug()

	/**
	 * The plain version of a route.
	 *
	 * @param string                $route The route, e.g. `/afval`.
	 * @param array<string, string> $extra More parameters, in order.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function plain(string $route, array $extra = []): string {
		return $this->with(base: $this->plainBase(), route: $route, extra: $extra);
	}//end plain()

	/**
	 * The full (JavaScript) page of a route.
	 *
	 * @param string                $route The route.
	 * @param array<string, string> $extra More parameters, in order.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function full(string $route, array $extra = []): string {
		return $this->with(base: $this->siteBase, route: $route, extra: $extra);
	}//end full()

	/**
	 * A link an author wrote, as the plain page links it: a route of this
	 * site to its plain version (with the link's own query kept), an http(s),
	 * mail or phone address as it is, anything else ''.
	 *
	 * @param string $link The authored link.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function authored(string $link): string {
		$link = trim($link);
		if (preg_match('#^/(?!/)#', $link) === 1) {
			$query = '';
			$mark = strpos($link, '?');
			if ($mark !== false) {
				$query = substr($link, $mark + 1);
				$link = substr($link, 0, $mark);
			}

			// Split by hand: parse_str() would turn `f.wooCategory` into
			// `f_wooCategory`, a parameter the page does not read.
			$extra = [];
			foreach (explode('&', $query) as $pair) {
				$parts = explode('=', $pair, 2);
				$name = rawurldecode(str_replace('+', ' ', $parts[0]));
				if ($name !== '' && in_array($name, ['route', 'portal'], true) === false) {
					$extra[$name] = rawurldecode(str_replace('+', ' ', ($parts[1] ?? '')));
				}
			}

			return $this->plain(route: $link, extra: $extra);
		}

		if (preg_match('#^(https?://|mailto:|tel:)#i', $link) === 1) {
			return $link;
		}

		return '';
	}//end authored()

	/**
	 * An address with the route, the portal and more parameters.
	 *
	 * @param string                $base  The address.
	 * @param string                $route The route.
	 * @param array<string, string> $extra More parameters.
	 *
	 * @return string
	 */
	private function with(string $base, string $route, array $extra): string {
		$values = ['route' => '/' . trim($route, '/'), 'portal' => $this->portalSlug] + $extra;
		$query = [];
		foreach ($values as $name => $value) {
			if ($value !== '') {
				$query[] = rawurlencode((string)$name) . '=' . str_replace('%2F', '/', rawurlencode($value));
			}
		}

		return $base . '?' . implode('&', $query);
	}//end with()
}//end class
