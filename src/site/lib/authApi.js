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
 * WHAT THIS FILE DOES NOT DO: it does not ENFORCE anything. Per-portal
 * authentication is enforced on the server — the content API refuses a read
 * the portal's `authentication.modes` / `minTrust` do not allow (401 without a
 * session, 403 when its trust level is too low; see
 * `ContentController::refuseUnlessPermitted()`). This module only offers the
 * door and reports who came through it. Hiding content here would guard
 * nothing, and no code here should be read as if it did.
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

	// The React portal's bearer leaves localStorage on the first read,
	// whichever bearer this tab ends up with (REQ-SRP-002).
	const legacy = adoptLegacyToken()

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
		return window.sessionStorage.getItem(TOKEN_KEY) || legacy
	} catch {
		return legacy
	}
}

/**
 * Where the retired React portal kept its bearer: localStorage, for every tab.
 */
export const LEGACY_TOKEN_KEY = 'portaliq_token'

/**
 * Take the bearer the React portal left in localStorage, once
 * (site-reaches-portal-parity REQ-SRP-002).
 *
 * A resident who signed in on `/portal` before it moved to the site still has
 * a valid bearer under the old key. Ignoring it would sign them out in
 * silence; keeping both stores would leave a bearer in localStorage that
 * outlives every tab. So it moves: into this tab's store, and out of
 * localStorage, whether or not it still works. A bearer that has expired then
 * reads as signed out, and the sign-in screen says what to do.
 *
 * @return {string} The adopted bearer, or ''.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-site-must-adopt-and-keep-one-bearer-for-the-resident-req-srp-002
 */
export function adoptLegacyToken() {
	let legacy
	try {
		legacy = window.localStorage.getItem(LEGACY_TOKEN_KEY) || ''
		if (legacy) {
			window.localStorage.removeItem(LEGACY_TOKEN_KEY)
		}
	} catch {
		return ''
	}

	if (legacy) {
		try {
			// A bearer this tab already holds is newer than the old one.
			if (!window.sessionStorage.getItem(TOKEN_KEY)) {
				window.sessionStorage.setItem(TOKEN_KEY, legacy)
			}
		} catch {
			// No session storage: the bearer lasts this page view.
		}
	}

	return legacy
}

/**
 * The one sentence a failed sign-in shows, whatever the reason
 * (signin-integriq-broker-login REQ-BEL-006). The edge sends no reason, so the
 * page cannot tell a prober which check failed.
 */
export const SIGNIN_FAILED_MESSAGE =
	'Inloggen is niet gelukt. Probeer het opnieuw of kies een andere manier.'

/**
 * Whether the edge sent the browser back from a failed sign-in, read from the
 * `#signin=failed` fragment and removed from the URL, like `#token=`.
 *
 * @return {boolean}
 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
 */
export function takeSigninFailed() {
	if (typeof window === 'undefined') {
		return false
	}

	if (String(window.location.hash || '') !== '#signin=failed') {
		return false
	}

	window.history.replaceState(
		null,
		'',
		window.location.pathname + window.location.search,
	)
	return true
}

/**
 * Keep a bearer for this tab: the one a dev login or a refresh minted. An
 * empty value forgets the stored one.
 *
 * @param {string|null} token The bearer.
 * @return {void}
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export function storeSessionToken(token) {
	try {
		if (token) {
			window.sessionStorage.setItem(TOKEN_KEY, token)
		} else {
			window.sessionStorage.removeItem(TOKEN_KEY)
		}
	} catch {
		// No storage: the bearer lasts this page view.
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
 * Rotate this tab's bearer (signin-session-idle-warning-and-sso T06). The
 * site keeps its bearer in sessionStorage; the rotated one replaces it. A
 * refusal (revoked, expired, past the cap) resolves null and changes nothing.
 *
 * @param {string} authBase The auth edge base.
 * @return {Promise<object|null>} The answer (token and session times), or null.
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
 */
export async function refreshSession(authBase) {
	try {
		const token = adoptSessionToken()
		if (!token) {
			return null
		}
		const response = await fetch(`${authBase}/session/refresh`, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				Authorization: `Bearer ${token}`,
			},
			credentials: 'include',
		})
		if (!response.ok) {
			return null
		}
		const body = await response.json()
		if (!body || !body.token) {
			return null
		}
		try {
			window.sessionStorage.setItem(TOKEN_KEY, body.token)
		} catch {
			// No storage: the page keeps the old bearer until it expires.
		}
		return body
	} catch {
		return null
	}
}

/**
 * The Dutch sign-in labels, for a caller that brings no translator.
 *
 * @param {string} key The English source string.
 * @param {object} [vars] Placeholder values.
 * @return {string} The Dutch label.
 */
function dutchLabel(key, vars = {}) {
	const nl = {
		'Log in with your account': 'Inloggen met uw account',
		'Log in': 'Inloggen',
		'Log in with {provider}': 'Inloggen met {provider}',
	}
	return (nl[key] || key).replace(/\{(\w+)\}/g, (_, name) =>
		String(vars[name] ?? ''),
	)
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
 * @param {(key: string, vars?: object) => string} [t] The site translator;
 *        without one the labels are the Dutch the site always showed.
 * @return {Array<{mode: string, label: string, card?: object, href: string}>} The routes.
 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
 */
export function signInRoutes(site, authBase, t = dutchLabel) {
	const modes = Array.isArray(site?.authentication?.modes)
		? site.authentication.modes
		: []

	const labels = {
		nextcloud: t('Log in with your account'),
		local: t('Log in'),
		oidc: t('Log in'),
		digid: t('Log in with {provider}', { provider: 'DigiD' }),
		eherkenning: t('Log in with {provider}', { provider: 'eHerkenning' }),
		eidas: t('Log in with {provider}', { provider: 'eIDAS' }),
	}

	// THE PORTAL SLUG TRAVELS WITH THE LINK, and leaving it off is not a
	// cosmetic omission. The auth edge falls back to resolving the portal by
	// HOST, and several portals share one host on a development instance — so a
	// sign-in started from portal B arrived at the edge looking like portal A
	// and was refused with `mode_not_offered`. Measured: the button led to a
	// 404 whose body named a mode the visitor's portal does in fact offer.
	const slug = site && site.slug ? String(site.slug) : ''
	const scope = slug ? `portal=${encodeURIComponent(slug)}` : ''
	// Come back to the page the visitor was actually on, not the portal root.
	const returnTo =
		typeof window !== 'undefined' && window.location
			? `returnTo=${encodeURIComponent(window.location.pathname + window.location.search)}`
			: ''
	const query = [scope, returnTo].filter(Boolean).join('&')

	// A MODE IS NOT A PROVIDER. The auth edge knows the providers digid,
	// eherkenning, eidas and generic. `local` and `oidc` are portal modes that
	// both sign in through the organisation's generic OIDC broker, so a link
	// carrying `provider=oidc` was refused whatever the organisation had
	// configured (#802).
	const providers = { local: 'generic', oidc: 'generic' }

	// The card a portal wrote for a way in (site-chrome-follows-the-design):
	// who it is for, what it opens, the button's text and a hint. A way in
	// without one keeps the standard label and no card text.
	const cards = site?.authentication?.modeLabels || {}

	return modes
		.filter((mode) => mode !== 'public' && Object.hasOwn(labels, mode))
		.map((mode) => ({
			mode,
			label: cards[mode]?.button || labels[mode],
			...(cards[mode] ? { card: cards[mode] } : {}),
			href:
				mode === 'nextcloud'
					? `${authBase}/session/nextcloud${query ? `?${query}` : ''}`
					: `${authBase}/session/oidc/start?provider=${encodeURIComponent(
							providers[mode] || mode,
						)}${query ? `&${query}` : ''}`,
		}))
}
