/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Open portal" — the row action that takes an administrator from a portal
 * record to the portal itself.
 *
 * WHY A HANDLER AND NOT A `navigate` ACTION. The manifest's `navigate` type
 * opens `action.target` verbatim; the `{field}` row-token grammar applies to a
 * route push's `params`, never to a URL target. The destination here is
 * per-row (`?portal=<this row's slug>`), so it cannot be a static target — and
 * the bare `/site` is exactly the not-found page this action exists to avoid.
 * `type: "handler"` hands the row to a function, which is what this is.
 *
 * WHY THE SLUG AT ALL. `PortalResolver` serves a site request by matching the
 * request HOST against the verified domains of published portals, and accepts
 * an explicit slug for a caller not reaching Portaliq over the site's own
 * hostname. Until a portal's domain is delegated and verified — every
 * development rig, and every instance before go-live — the slug form is the
 * only way in, and the admin UI is precisely such a caller.
 *
 * WHY `generateUrl` AND NOT A CONCATENATED PATH. `generateUrl` keeps
 * `/index.php` where mod_rewrite is off and drops it where it is on. Two bugs
 * in this app came from getting that wrong by hand — the task gateway
 * (WOO-568) and the notification-mail deeplink (WOO-570) both 404'd on an
 * instance without pretty URLs.
 *
 * WHY THIS MODULE IMPORTS NOTHING. Its collaborators — the URL generator, the
 * toast, the translator — are handed in by `src/customComponents.js`, which is
 * where the app already wires Vue-land into the registries. Importing
 * `@nextcloud/dialogs` here instead would pull that package's stylesheet into
 * the module graph, and `node tests/open-portal-site.spec.mjs` then dies on
 * `Unknown file extension ".css"` before asserting anything. Same shape as
 * `src/site/lib/authApi.js`, which `tests/site-auth.spec.mjs` exercises as a
 * plain node script.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site
 */

/** The app route that renders a portal's public site (`portalPage#site`). */
const SITE_PATH = '/apps/portaliq/site'

/**
 * Build the public-site URL for one portal slug.
 *
 * Exported on its own because the URL is the part worth asserting, and a
 * caller that only wants the address (a copy-link affordance, a test) should
 * not have to stub `window.open`.
 *
 * @param {string}   slug          The portal's slug, as stored on the object.
 * @param {(path: string) => string} generateUrlFn Nextcloud's URL generator.
 * @return {string} The site URL carrying the encoded `portal` parameter.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site
 */
export function portalSiteUrl(slug, generateUrlFn) {
	// encodeURIComponent, not a template literal: a slug carrying `&`, `#`, a
	// space or non-ASCII would otherwise split the query or resolve to a
	// different portal than the row names.
	return `${generateUrlFn(SITE_PATH)}?portal=${encodeURIComponent(slug)}`
}

/**
 * Create the row-action handler.
 *
 * @param {object} deps Collaborators, supplied by the caller.
 * @param {(path: string) => string} deps.generateUrl Nextcloud's URL generator.
 * @param {(message: string) => void} deps.notify Shows the message when a row has no slug.
 * @param {(text: string) => string} deps.translate Translates that message.
 * @param {(url: string, target: string, features: string) => void} [deps.open] Window opener; defaults to `window.open`. Its return value is deliberately ignored — see the call site.
 * @return {(payload?: {actionId?: string, item?: object}) => string|null} The row-action handler CnIndexPage calls.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portals-overview-must-open-a-portals-public-site
 */
export function createOpenPortalSite({
	generateUrl: generateUrlFn,
	notify,
	translate,
	open = (...args) => window.open(...args),
} = {}) {
	/**
	 * Open the row's portal site in a new tab.
	 *
	 * @param {object} payload      The dispatch payload from CnIndexPage.
	 * @param {object} payload.item The row the action was triggered on.
	 * @return {string|null} The opened URL, or null when the row has no slug.
	 */
	return function openPortalSite({ item } = {}) {
		// The slug is addressed EXACTLY as stored. `PortalResolver::resolve()`
		// compares `$site['slug'] === $portalSlug`, so trimming here would
		// address a different portal than the row names for a record stored
		// as " demo" — a miss that looks like the site is broken. The trim is
		// for the emptiness TEST only.
		const slug = typeof item?.slug === 'string' ? item.slug : ''
		if (slug.trim() === '') {
			// A portal with no slug cannot be addressed at all — the resolver
			// compares the requested slug to `site.slug`, so an empty one
			// matches nothing. Saying so beats opening the site's not-found
			// page and letting the administrator guess why.
			notify(
				translate(
					'This portal has no slug yet, so it has no public address.',
				),
			)
			return null
		}

		const url = portalSiteUrl(slug, generateUrlFn)
		// `noopener,noreferrer`: the opened document renders portal-authored
		// content and must not reach back into the admin window through
		// `window.opener`.
		//
		// ITS RETURN VALUE IS NOT A SUCCESS SIGNAL. `noopener` severs the
		// WindowProxy, so the HTML standard has `window.open` return null for
		// a SUCCESSFUL open ("If noopener is true, then return null", window
		// open steps). Measured in Chromium on 2026-09-11: called with
		// `noopener,noreferrer` inside a click, the tab opens and the call
		// still returns null; the identical call without those features
		// returns a WindowProxy. An earlier cut of this file read that value
		// and told the administrator the site could not be opened on every
		// open that worked (#513, review round 2).
		//
		// A refused tab therefore cannot be told apart from an opened one
		// here, and is left to the browser's own blocked-popup indicator.
		// Severing the opener is the property worth keeping, and this call
		// runs synchronously inside the click gesture, which is what keeps
		// blockers out of the way to begin with.
		open(url, '_blank', 'noopener,noreferrer')

		return url
	}
}
