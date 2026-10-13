<?php

/**
 * Portaliq Host Root Redirect (portal-host-root-redirect)
 *
 * Sends a visitor of the bare address of a portal's verified domain to the
 * site, before Nextcloud answers that address with its login or default app.
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
 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\IRequest;
use Throwable;

/**
 * The root of a Nextcloud instance belongs to core: when no route matches
 * `/`, `OC::handleRequest()` sends a guest to the login page and a signed-in
 * user to the default app, for every host alike. An app cannot add a route
 * there (only a short list of core apps may own root URLs). What an app can
 * do is act in its `boot()`, which core runs for every app before it matches
 * the route, and end the request itself.
 *
 * This class decides whether a request is that bare address and, on a host
 * that is a verified domain of a published portal, sends the visitor to the
 * site. Every other request, on every other host, is left alone, so core
 * answers it exactly as it would without this app. `/login` is a path of its
 * own and is never touched: an employee can still sign in on a portal host.
 *
 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
 */
class HostRootRedirect {
	/**
	 * Constructor.
	 *
	 * @param SiteAddressing       $siteAddressing Names where the bare address of a portal host leads.
	 * @param HostRootRedirectSend $send           Ends the request with the redirect.
	 */
	public function __construct(
		private readonly SiteAddressing $siteAddressing,
		private readonly HostRootRedirectSend $send,
	) {
	}//end __construct()

	/**
	 * Send the visitor of a portal host's bare address to the site.
	 *
	 * Returns only when the request is left to Nextcloud: a redirect ends it.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-the-bare-address-of-a-portal-host-must-lead-to-its-site-req-hrr-001
	 */
	public function handle(IRequest $request): void {
		$target = $this->target(request: $request);
		if ($target !== null) {
			$this->send->redirect(location: $target);
		}
	}//end handle()

	/**
	 * Where this request should be sent, or null to leave it to Nextcloud.
	 *
	 * Null unless the request is the bare address of the instance (`/`,
	 * `/index.php`, `/index.php/`) through the front controller, asked for
	 * as a page, and its host is a verified domain of a published portal.
	 * The cheap checks on the request come first, so the portals are only
	 * read for a request that could be redirected.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return string|null The address, a path on the same host.
	 *
	 * @spec openspec/changes/portal-host-root-redirect/specs/portal-settings/spec.md#requirement-any-other-host-must-keep-nextclouds-own-root-req-hrr-002
	 */
	public function target(IRequest $request): ?string {
		if ($this->isBareAddress(request: $request) === false) {
			return null;
		}

		try {
			return $this->siteAddressing->hostRoot(request: $request);
		} catch (Throwable) {
			// Plain Nextcloud, never a guess.
			return null;
		}
	}//end target()

	/**
	 * Whether the request is the bare address of the instance, asked for as
	 * a page.
	 *
	 * Core answers the same requests at the same point: an empty path info
	 * (which `/`, `/index.php` and `/index.php/` all give) through
	 * `index.php`, and not a JSON or XML client, which core answers with 404.
	 * The script name rules out `cron.php`, `status.php`, `remote.php` and
	 * the console, which give an empty path info too.
	 *
	 * @param IRequest $request The request.
	 *
	 * @return bool
	 */
	private function isBareAddress(IRequest $request): bool {
		if (in_array($request->getMethod(), ['GET', 'HEAD'], true) === false) {
			return false;
		}

		$script = $request->getScriptName();
		if ($script !== 'index.php' && str_ends_with($script, '/index.php') === false) {
			return false;
		}

		try {
			if ($request->getRawPathInfo() !== '') {
				return false;
			}
		} catch (Throwable) {
			return false;
		}

		return in_array($request->getHeader('Accept'), ['application/json', 'application/xml'], true) === false;
	}//end isBareAddress()
}//end class
