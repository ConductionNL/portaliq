<?php

/**
 * Portaliq Site Addressing (site-at-the-root-of-a-portal-host)
 *
 * How the pages of a site are addressed on the host a request came in on:
 * under the app's own site route, or at the root of a verified domain.
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
 *
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IRequest;
use OCP\IURLGenerator;
use Throwable;

/**
 * The addressing of the site for one request.
 *
 * A site page has two addresses. The long one always works:
 * `/apps/portaliq/site?route=/afval`, or the same page as a path,
 * `/apps/portaliq/site/afval`. The short one, `/afval` on the portal's own
 * host, only works where a proxy in front of Nextcloud rewrites it to the long
 * one, because an app cannot answer at the root of a Nextcloud instance. The
 * app cannot see that proxy, so an administrator declares it per domain
 * (`servedAtRoot`). Everything the server writes for the visitor (the
 * canonical address, the addressing handed to the renderer) follows that
 * declaration.
 *
 * Both settings are read from the verified domain the request host matches
 * and from nowhere else. A host that resolves no portal gets the long
 * addressing and no landing page, whatever any portal declares.
 *
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
 */
class SiteAddressing {
	/**
	 * The longest landing route kept. A route is a page address, not a text.
	 */
	private const ROUTE_MAX = 512;

	/**
	 * Constructor.
	 *
	 * @param PortalResolver $portalResolver Names the verified domain a request is served on.
	 * @param IURLGenerator  $urlGenerator   Builds the long site address.
	 */
	public function __construct(
		private readonly PortalResolver $portalResolver,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * What the renderer needs to read and write page addresses.
	 *
	 * `root` is true only on a verified domain that declares `servedAtRoot`.
	 * `siteRoute` is the long address of the site, so the renderer can tell
	 * a browser that sits on it from one that sits on a short address.
	 * `landingRoute` is the page the bare address of this host opens, or ''.
	 *
	 * @param IRequest $request    The request.
	 * @param string   $portalSlug The portal the request names, or ''.
	 *
	 * @return array{root: bool, siteRoute: string, landingRoute: string}
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
	 */
	public function forRequest(IRequest $request, string $portalSlug): array {
		$domain = $this->domain(request: $request, portalSlug: $portalSlug);

		return [
			'root'         => (($domain['servedAtRoot'] ?? false) === true),
			'siteRoute'    => $this->urlGenerator->linkToRoute('portaliq.portalPage.site'),
			'landingRoute' => $this->cleanRoute(route: ($domain['landingRoute'] ?? '')),
		];
	}//end forRequest()

	/**
	 * The site route this request asks for.
	 *
	 * In order: the path under the site route (`/site/afval`), the `?route=`
	 * parameter, the landing route of the host, and the home page.
	 *
	 * @param IRequest $request    The request.
	 * @param string   $portalSlug The portal the request names, or ''.
	 * @param string   $path       The path under the site route, or ''.
	 *
	 * @return string The route, always with a leading slash.
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-domain-may-open-on-a-page-of-its-own-req-srh-002
	 */
	public function route(IRequest $request, string $portalSlug, string $path = ''): string {
		$path = trim($path, '/');
		if ($path !== '') {
			return '/' . $path;
		}

		$named = (string)$request->getParam('route', '');
		if ($named !== '') {
			return '/' . ltrim($named, '/');
		}

		$landing = $this->forRequest(request: $request, portalSlug: $portalSlug)['landingRoute'];
		if ($landing !== '') {
			return $landing;
		}

		return '/';
	}//end route()

	/**
	 * The canonical address of a site page on this request's host.
	 *
	 * On a domain that serves the site at its root this is the short address,
	 * built from the host Nextcloud resolved for the request and never from a
	 * header. Anywhere else it is the long address, as before.
	 *
	 * @param IRequest $request    The request.
	 * @param string   $portalSlug The portal the request names, or ''.
	 * @param string   $route      The site route.
	 *
	 * @return string The absolute address.
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/specs/portal-settings/spec.md#requirement-a-verified-domain-may-serve-the-site-at-its-root-req-srh-001
	 */
	public function canonical(IRequest $request, string $portalSlug, string $route): string {
		if ($this->forRequest(request: $request, portalSlug: $portalSlug)['root'] === true) {
			$segments = array_map('rawurlencode', explode('/', ltrim($route, '/')));

			return $request->getServerProtocol() . '://' . $request->getServerHost() . '/' . implode('/', $segments);
		}

		$params = ['route' => $route];
		if ($portalSlug !== '') {
			$params['portal'] = $portalSlug;
		}

		return $this->urlGenerator->linkToRouteAbsolute('portaliq.portalPage.site', $params);
	}//end canonical()

	/**
	 * Where the bare address of this request's host should lead, or null.
	 *
	 * Only a host that is a verified domain of a published portal has an
	 * answer: the long address of the site, on the page the domain opens
	 * (`landingRoute`), or on the home page. The answer is a path on the same
	 * host, built from the route table and the stored, cleaned landing route.
	 * Nothing from the request goes into it but the host that selected the
	 * domain, and that host is not written into it either.
	 *
	 * It is always the long address, also on a domain that declares
	 * `servedAtRoot`. Behind the proxy rule that declaration stands for, the
	 * bare address never reaches Nextcloud's root. When it does reach it, the
	 * rule is missing, and a short address would lead back to the root.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string|null The address, or null for a host that resolves no portal.
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-any-other-host-must-keep-nextclouds-own-root-req-hrr-002
	 */
	public function hostRoot(IRequest $request): ?string {
		$domain = $this->domain(request: $request, portalSlug: '');
		if ($domain === null) {
			return null;
		}

		$landing = $this->cleanRoute(route: ($domain['landingRoute'] ?? ''));
		if ($landing === '') {
			return $this->urlGenerator->linkToRoute('portaliq.portalPage.site');
		}

		return $this->urlGenerator->linkToRoute('portaliq.portalPage.sitePath', ['path' => ltrim($landing, '/')]);
	}//end hostRoot()

	/**
	 * The verified domain this request is served on, or null. A resolver that
	 * throws answers null: the long addressing, never another host's settings.
	 *
	 * @param IRequest $request    The request.
	 * @param string   $portalSlug The portal the request names, or ''.
	 *
	 * @return array<string, mixed>|null
	 */
	private function domain(IRequest $request, string $portalSlug): ?array {
		try {
			return $this->portalResolver->servingDomain(request: $request, portalSlug: $portalSlug);
		} catch (Throwable) {
			return null;
		}
	}//end domain()

	/**
	 * A landing route when it is a plain path on this site, else ''.
	 *
	 * Refused: anything that is not a string, does not start with one slash,
	 * or carries a second slash in a row, a backslash, a scheme, a query, a
	 * fragment, whitespace or a control character. A landing route decides
	 * where the bare address of a host leads, so it may never lead off it.
	 *
	 * @param mixed $route The stored value.
	 *
	 * @return string The route, or ''.
	 */
	private function cleanRoute(mixed $route): string {
		if (is_string($route) === false) {
			return '';
		}

		$route = trim($route);
		if ($route === '' || $route === '/' || strlen($route) > self::ROUTE_MAX || $route[0] !== '/') {
			return '';
		}

		if (preg_match('/[\\\\?#:\s\x00-\x1f\x7f]/', $route) === 1 || str_contains($route, '//') === true) {
			return '';
		}

		return $route;
	}//end cleanRoute()
}//end class
