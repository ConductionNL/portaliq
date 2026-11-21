<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Signin
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Signin;

use OCA\Portaliq\Kernel\Contracts\PortalResolverInterface;
use OCA\Portaliq\Kernel\Contracts\SiteReturnAddressInterface;
use OCP\IRequest;
use Throwable;

/**
 * Where a login started on the public site may return to.
 *
 * The site sends the page the resident was on (`/apps/portaliq/site?portal=
 * wilgenboom&route=/mijn/inbox`), so the login lands back on that page with
 * its portal named. Only an address on the site route itself is accepted: a
 * path, never a host, a scheme, a protocol-relative `//`, a backslash or a
 * fragment, so the return address can never send a bearer to another origin.
 * Anything else answers '' and the caller keeps its own default.
 *
 * On a host that serves the site at its root (`servedAtRoot` on its verified
 * domain), the site's pages have short addresses (`/afval`, `/mijn/inbox`),
 * and a sign-in started there returns to that short address on the same host.
 * A short address is a plain path of the site: the same refusals apply, and a
 * Nextcloud path (`/index.php`, `/apps/`, `/ocs`, `/remote.php`, ...) or a
 * dot segment is never short.
 *
 * @spec openspec/changes/archive/2026-11-12-portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#T8
 */
final class SiteReturnAddress implements SiteReturnAddressInterface {

	/**
	 * First path segments that belong to Nextcloud, never to the site.
	 */
	private const NEXTCLOUD_SEGMENTS = [
		'index.php',
		'apps',
		'ocs',
		'ocs-provider',
		'remote.php',
		'public.php',
		'status.php',
		'core',
		'login',
		'logout',
		'settings',
		's',
		'.well-known',
	];

	/**
	 * Constructor.
	 *
	 * @param PortalResolverInterface $portalResolver Reads the verified domain the request is served on.
	 * @param IRequest                $request        The request the sign-in arrived on.
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#T8
	 */
	public function __construct(
		private readonly PortalResolverInterface $portalResolver,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * The return address when it is a page of the site, else ''.
	 *
	 * The `/index.php` front controller is optional on both sides, so an
	 * instance with or without pretty URLs compares the same path.
	 *
	 * @param string $candidate The `returnTo` the site sent.
	 * @param string $sitePath  The site route's own path, from the URL generator.
	 *
	 * @return string The accepted address, or ''.
	 *
	 * @spec openspec/changes/archive/2026-11-12-portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#T8
	 */
	public function accept(string $candidate, string $sitePath): string {
		$sitePath = $this->withoutFrontController(path: $sitePath);
		if ($this->comparable(candidate: $candidate, sitePath: $sitePath) === false) {
			return '';
		}

		[$path, $query] = $this->pathAndQuery(candidate: $candidate);
		if ($this->withoutFrontController(path: $path) === $sitePath) {
			return $path . $query;
		}

		if ($this->isShortPath(path: $path) === true && $this->servedAtRoot() === true) {
			return $path . $query;
		}

		return '';
	}//end accept()

	/**
	 * Whether a path can be a short address of the site.
	 *
	 * @param string $path The candidate's path, without its query.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#T8
	 */
	private function isShortPath(string $path): bool {
		if ($path === '' || $path[0] !== '/' || str_contains($path, ':') === true) {
			return false;
		}

		$segments = explode('/', substr($path, 1));
		if (in_array($segments[0], self::NEXTCLOUD_SEGMENTS, true) === true) {
			return false;
		}

		return in_array('.', $segments, true) === false && in_array('..', $segments, true) === false;
	}//end isShortPath()

	/**
	 * Whether the request's host serves the site at its root.
	 *
	 * Only the host decides: no portal is named, so a verified domain answers
	 * for its own portal and an unknown or unverified host answers false.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-at-the-root-of-a-portal-host/tasks.md#T8
	 */
	private function servedAtRoot(): bool {
		try {
			$domain = $this->portalResolver->servingDomain(request: $this->request);
		} catch (Throwable) {
			return false;
		}

		return (($domain['servedAtRoot'] ?? false) === true);
	}//end servedAtRoot()

	/**
	 * Whether a candidate can be compared with the site path at all.
	 *
	 * @param string $candidate The `returnTo` the site sent.
	 * @param string $sitePath  The site route's own path, without the front controller.
	 *
	 * @return bool
	 */
	private function comparable(string $candidate, string $sitePath): bool {
		if ($candidate === '' || $sitePath === '' || $sitePath[0] !== '/') {
			return false;
		}

		return $this->staysHere(candidate: $candidate);
	}//end comparable()

	/**
	 * Whether a candidate stays on this origin and path.
	 *
	 * @param string $candidate The `returnTo` the site sent.
	 *
	 * @return bool
	 */
	private function staysHere(string $candidate): bool {
		// Every way out of this origin or out of the path is refused before the
		// path is compared: `//host`, `\host`, a fragment, whitespace and
		// control characters. A scheme cannot pass the comparison below.
		if (preg_match('/[\\\\#\s\x00-\x1f\x7f]/', $candidate) === 1 || str_contains($candidate, '//') === true) {
			return false;
		}

		return true;
	}//end staysHere()

	/**
	 * The candidate's path and its query, the question mark included.
	 *
	 * @param string $candidate The `returnTo` the site sent.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function pathAndQuery(string $candidate): array {
		$path  = $candidate;
		$query = '';
		$mark  = strpos($candidate, '?');
		if ($mark !== false) {
			$path  = substr($candidate, 0, $mark);
			$query = substr($candidate, $mark);
		}

		return [$path, $query];
	}//end pathAndQuery()

	/**
	 * A path without its leading `/index.php`.
	 *
	 * @param string $path The path.
	 *
	 * @return string The path.
	 */
	private function withoutFrontController(string $path): string {
		if (str_starts_with($path, '/index.php/') === true) {
			return substr($path, strlen('/index.php'));
		}

		return $path;
	}//end withoutFrontController()
}//end class
