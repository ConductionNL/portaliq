/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The renderer's view of the portal auth edge.
 *
 * SEPARATE FROM `contentApi.js` ON PURPOSE. Everything in that file is the
 * headless CONTRACT — a Docusaurus build reads exactly those endpoints. This
 * file is not part of that contract: a third-party front-end brings its own
 * session handling, and pretending otherwise would put a Portaliq-specific
 * auth edge inside the promise that any consumer can reproduce a portal.
 *
 * What IS on the contract is the DECISION: `authentication.modes` comes from
 * `/api/content/site`, so every consumer learns which sign-in routes a portal
 * offers from the public API. Only the act of using them lives here.
 *
 * WHAT THIS FILE DOES NOT DO — stated because the gap is easy to mistake for a
 * bug: it does not ENFORCE anything. Per-portal authentication is declared in
 * the schema and enforced nowhere yet; every portal behaves as `public`
 * read-only. This module offers the door and reports who came through it. It
 * does not guard any content, and no code here should be read as if it did.
 */

/**
 * The auth edge's base, derived from the content API base.
 *
 * `/api/content` and `/portal/api` are siblings under the app root, so the
 * edge is found by replacing the last segment pair rather than by hardcoding
 * a Nextcloud path — the same reason `contentApi.js` resolves its own base
 * from runtime configuration.
 *
 * @param {string} apiBase The content API base, no trailing slash.
 * @return {string} The auth edge base.
 */
export function authBaseFrom(apiBase) {
	return String(apiBase || '').replace(/\/api\/content\/?$/, '/portal/api')
}

/**
 * The current portal session, or null when there is none.
 *
 * A failure to reach the edge is NOT an error the visitor should see: a portal
 * whose auth edge is down must still serve its public content, which is the
 * overwhelming majority of what it serves. So this resolves to null on any
 * problem and the page renders signed-out.
 *
 * @param {string} authBase The auth edge base.
 * @return {Promise<object|null>} The session subject, or null.
 */
/**
 * Where the portal bearer lives in the browser.
 *
 * `sessionStorage`, not `localStorage`: a portal session is a visit, not a
 * standing relationship, and a citizen's session on a shared machine should not
 * outlive the tab. It still survives a refresh, which `localStorage` is usually
 * reached for by mistake.
 */
const TOKEN_KEY = 'portaliq.session.token'

/**
 * Adopt a bearer handed back in the URL fragment, and remove it from the URL.
 *
 * THE EDGE HAS ALWAYS REDIRECTED WITH `#token=…` AND NOTHING EVER READ IT. Both
 * the OIDC callback and the Nextcloud sign-in end by sending the browser back
 * with the bearer in the fragment — the fragment precisely because it is never
 * sent to a server and never reaches a log. Without this the round-trip
 * completed, the URL carried a valid token, and the portal still rendered
 * signed-out: a login that looks like a silent failure and is actually an
 * un-collected success.
 *
 * The fragment is stripped once adopted, so the token does not sit in the
 * address bar, in `history`, or in the next screenshot someone pastes into a
 * ticket.
 *
 * @return {string} The adopted token, or the stored one, or ''.
 */
export function adoptSessionToken() {
	if (typeof window === 'undefined') {
		return ''
	}

	const hash = String(window.location.hash || '')
	const match = hash.match(/[#&]token=([^&]+)/)
	if (match) {
		const token = decodeURIComponent(match[1])
		try {
			window.sessionStorage.setItem(TOKEN_KEY, token)
		} catch {
			// Private mode, or storage disabled. The session then lasts exactly
			// this page view, which is a degraded login rather than a broken one.
		}

		const clean = window.location.pathname + window.location.search
		window.history.replaceState(null, '', clean)
		return token
	}

	try {
		return window.sessionStorage.getItem(TOKEN_KEY) || ''
	} catch {
		return ''
	}
}

/**
 * Forget the stored bearer.
 *
 * @return {void}
 */
export function clearSessionToken() {
	try {
		window.sessionStorage.removeItem(TOKEN_KEY)
	} catch {
		// Nothing stored, nothing to forget.
	}
}

export async function fetchSession(authBase) {
	try {
		const token = adoptSessionToken()
		const headers = { Accept: 'application/json' }
		if (token) {
			headers.Authorization = `Bearer ${token}`
		}

		const response = await fetch(`${authBase}/session`, {
			headers,
			credentials: 'include',
		})
		if (!response.ok) {
			return null
		}

		const body = await response.json()
		// The edge answers `{authenticated: false}` for an anonymous caller
		// rather than a 401, so the flag is what decides — not the status.
		return body && body.authenticated ? body : null
	} catch {
		return null
	}
}

/**
 * The sign-in routes a portal offers, derived from its declared modes.
 *
 * `public` is not a sign-in route — it is the absence of one. A portal that
 * declares ONLY `public` must show no login affordance at all, and that is the
 * case worth getting right: an inert "Sign in" button on a portal that has no
 * accounts is a support ticket from every visitor who presses it.
 *
 * @param {object} site     The portal record from /api/content/site.
 * @param {string} authBase The auth edge base.
 * @return {Array<{mode: string, label: string, href: string}>} The routes.
 */
export function signInRoutes(site, authBase) {
	const modes = Array.isArray(site?.authentication?.modes)
		? site.authentication.modes
		: []

	const labels = {
		nextcloud: 'Inloggen met uw account',
		local: 'Inloggen',
		oidc: 'Inloggen',
		digid: 'Inloggen met DigiD',
		eherkenning: 'Inloggen met eHerkenning',
		eidas: 'Inloggen met eIDAS',
	}

	// THE PORTAL SLUG TRAVELS WITH THE LINK, and leaving it off is not a
	// cosmetic omission. The auth edge falls back to resolving the portal by
	// HOST, and several portals share one host on a development instance — so a
	// sign-in started from portal B arrived at the edge looking like portal A
	// and was refused with `mode_not_offered`. Measured: the button led to a
	// 404 whose body named a mode the visitor's portal does in fact offer.
	const slug = (site && site.slug) ? String(site.slug) : ''
	const scope = slug ? `portal=${encodeURIComponent(slug)}` : ''
	// Come back to the page the visitor was actually on, not the portal root.
	const returnTo = (typeof window !== 'undefined' && window.location)
		? `returnTo=${encodeURIComponent(window.location.pathname + window.location.search)}`
		: ''
	const query = [scope, returnTo].filter(Boolean).join('&')

	return modes
		.filter((mode) => mode !== 'public' && Object.hasOwn(labels, mode))
		.map((mode) => ({
			mode,
			label: labels[mode],
			href:
				mode === 'nextcloud'
					? `${authBase}/session/nextcloud${query ? `?${query}` : ''}`
					: `${authBase}/session/oidc/start?provider=${encodeURIComponent(
							mode === 'local' ? 'generic' : mode,
						)}${query ? `&${query}` : ''}`,
		}))
}
