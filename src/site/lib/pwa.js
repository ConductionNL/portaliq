// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Makes the site installable (site-reaches-portal-parity REQ-SRP-045): the
// site's half of the service worker that PortalManifestController serves from
// src/shared/serviceWorker.js.
//
// FAIL-SILENT BY DESIGN. A browser without service workers, a private window
// that refuses them, or a registration that rejects must never stop the site
// from booting: installability is additive, not load-bearing. So this never
// throws and never returns a rejected promise.
//
// Framework-free on purpose, so tests/service-worker.spec.mjs runs it in node.

/**
 * Where the worker is served and what it may control, from the auth edge base.
 *
 * The worker route sits next to the auth edge (`…/portal/sw.js` beside
 * `…/portal/api`), and its `Service-Worker-Allowed` header allows the whole
 * app path, so the scope is the app root: that covers `/site` as well as
 * `/portal`.
 *
 * @param {string} authBase The auth edge base, e.g. `/index.php/apps/portaliq/portal/api`.
 * @return {{url: string, scope: string}|null} The worker address and scope, or null when the base is not one.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-service-worker-must-cache-the-site-shell-req-srp-045
 */
export function serviceWorkerAddress(authBase) {
	const base = String(authBase || '').replace(/\/$/, '')
	if (!/\/portal\/api$/.test(base)) {
		return null
	}

	const portal = base.replace(/\/api$/, '')
	return {
		url: `${portal}/sw.js`,
		scope: `${portal.replace(/\/portal$/, '')}/`,
	}
}

/**
 * Register the shell-caching worker for the site.
 *
 * Call it once at boot, after the app has mounted:
 * `registerSiteServiceWorker(authBaseFrom(resolveApiBase()))`.
 *
 * @param {string} authBase The auth edge base (`authBaseFrom(resolveApiBase())`).
 * @param {object} [options] Options.
 * @param {object} [options.nav] The navigator (test seam).
 * @return {Promise<object|null>} The registration, or null when there is none.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-service-worker-must-cache-the-site-shell-req-srp-045
 */
export async function registerSiteServiceWorker(
	authBase,
	{ nav = typeof navigator === 'undefined' ? null : navigator } = {},
) {
	try {
		const address = serviceWorkerAddress(authBase)
		const container = nav?.serviceWorker
		if (address === null || !container || typeof container.register !== 'function') {
			return null
		}

		return await container.register(address.url, { scope: address.scope })
	} catch {
		// Best-effort only; see the file header.
		return null
	}
}
