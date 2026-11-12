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
		return takeLinkToken(match[1])
	}

	return tabToken(legacy)
}

/**
 * Keep a sign-in link's bearer for this tab and take it off the address bar.
 *
 * @param {string} encoded The bearer as the fragment carries it.
 * @return {string} The bearer.
 */
function takeLinkToken(encoded) {
	const token = decodeURIComponent(encoded)
	// Private mode, or storage disabled: the session then lasts exactly this
	// page view, which is a degraded login rather than a broken one.
	keepTabToken(token)

	const clean = window.location.pathname + window.location.search
	window.history.replaceState(null, '', clean)
	return token
}

/**
 * Keep a bearer in this tab's store; without a store it is kept nowhere.
 *
 * @param {string} token The bearer.
 * @return {void}
 */
function keepTabToken(token) {
	try {
		window.sessionStorage.setItem(TOKEN_KEY, token)
	} catch {
		// No session storage: the caller's bearer lasts this page view.
	}
}

/**
 * The bearer this tab keeps, else the given fallback.
 *
 * @param {string} fallback The bearer to answer without one, or ''.
 * @return {string} The bearer.
 */
function tabToken(fallback) {
	try {
		return window.sessionStorage.getItem(TOKEN_KEY) || fallback
	} catch {
		return fallback
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
 * @spec openspec/specs/site-portal-parity/spec.md#requirement-the-site-must-adopt-and-keep-one-bearer-for-the-resident-req-srp-002
 */
export function adoptLegacyToken() {
	let legacy
	try {
		legacy = takeLegacyToken()
	} catch {
		return ''
	}

	if (legacy) {
		keepUnlessHeld(legacy)
	}

	return legacy
}

/**
 * Take the old bearer out of localStorage; throws where the browser refuses
 * the store.
 *
 * @return {string} The old bearer, or ''.
 */
function takeLegacyToken() {
	const legacy = window.localStorage.getItem(LEGACY_TOKEN_KEY) || ''
	if (legacy) {
		window.localStorage.removeItem(LEGACY_TOKEN_KEY)
	}
	return legacy
}

/**
 * Keep the old bearer for this tab, unless the tab already holds one: that
 * one is newer.
 *
 * @param {string} legacy The old bearer.
 * @return {void}
 */
function keepUnlessHeld(legacy) {
	try {
		if (!window.sessionStorage.getItem(TOKEN_KEY)) {
			window.sessionStorage.setItem(TOKEN_KEY, legacy)
		}
	} catch {
		// No session storage: the bearer lasts this page view.
	}
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
 * @spec openspec/changes/archive/2026-11-12-portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
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
		const response = await fetch(`${authBase}/session`, {
			headers: sessionHeaders(),
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
 * The headers of a session read, with this tab's bearer when it has one.
 *
 * @return {object} The headers.
 */
function sessionHeaders() {
	const token = adoptSessionToken()
	const headers = { Accept: 'application/json' }
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	return headers
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
		const response = await fetch(`${authBase}/session/refresh`, rotationRequest(token))
		if (!response.ok) {
			return null
		}
		return keptRotation(await response.json())
	} catch {
		return null
	}
}

/**
 * The request that rotates a bearer.
 *
 * @param {string} token The bearer to rotate.
 * @return {object} The request init.
 */
function rotationRequest(token) {
	return {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			Authorization: `Bearer ${token}`,
		},
		credentials: 'include',
	}
}

/**
 * Keep the rotated bearer for this tab; without storage the page keeps the
 * old bearer until it expires.
 *
 * @param {object|null} body The rotation's answer.
 * @return {object|null} The answer, or null when it carries no bearer.
 */
function keptRotation(body) {
	if (!body || !body.token) {
		return null
	}
	keepTabToken(body.token)
	return body
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
		'Send me a sign-in link': 'Stuur mij een inloglink',
		'Log in as {name}': 'Inloggen als {name}',
		'Log in as the example resident': 'Inloggen als voorbeeldinwoner',
		'Example resident': 'Voorbeeldinwoner',
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
 * @param {object} ways     What the ways in are built from.
 * @param {string} ways.authBase The auth edge base.
 * @param {(key: string, vars?: object) => string} [ways.t] The site translator;
 *        without one the labels are the Dutch the site always showed.
 * @param {string} [ways.exampleResident] The example set whose residents the
 *        one-click demo sign-in offers on this portal (the shell's
 *        `signin.exampleResident`), or ''.
 * @param {Array<{key: string, name: string, card?: object}>} [ways.demoResidents]
 *        The residents of that set (`signin.exampleResidents`). Each gets its
 *        own card marked `demo`, after the portal's own ways in. The
 *        `nextcloud` way stays the account route, with its password reset and
 *        registration links, so a resident who made an account still signs in
 *        with it (demo-sign-in-beside-the-account).
 * @param {boolean} [ways.emailLink] Whether the portal offers the e-mail link (`waysIn.emailLink`).
 * @return {Array<{key: string, mode: string, label: string, card?: object, href: string, demo?: boolean, form?: string}>} The routes.
 * @spec openspec/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#1
 * @spec openspec/specs/example-resident/spec.md#requirement-a-demo-may-sign-the-example-resident-in-with-one-click
 * @spec openspec/changes/nextcloud-account-registration/specs/portal-nextcloud-account-login/spec.md#requirement-the-sign-in-page-leads-to-nextclouds-own-registration-req-ncl-005
 * @spec openspec/changes/demo-sign-in-beside-the-account/specs/example-resident/spec.md#requirement-a-demo-may-sign-in-each-example-resident-with-one-click
 */
export function signInRoutes(site, ways) {
	const { authBase, t = dutchLabel, exampleResident = '', demoResidents = [], emailLink = false } = ways
	const modes = Array.isArray(site?.authentication?.modes)
		? site.authentication.modes
		: []
	const labels = modeLabels(t, emailLink)
	const ctx = { site, authBase, t, exampleResident, ...returnQuery(site) }
	const routes = modes
		.filter((mode) => mode !== 'public' && Object.hasOwn(labels, mode))
		.map((mode) => modeRoute(mode, { ...ctx, label: labels[mode] }))

	// The one-click demo cards (demo-sign-in-beside-the-account): one per
	// example resident, only where the shell offers them and the portal
	// signs residents in with their account, as the route requires.
	const demo = typeof exampleResident === 'string' && exampleResident !== ''
	if (!demo || !modes.includes('nextcloud')) {
		return routes
	}
	return routes.concat(demoRoutes(demoResidents, ctx))
}

/**
 * The standard label of each way in. The e-mail link is a form, not a link:
 * only where the auth edge says the switch is on and the portal declares it
 * (sign-in-with-an-email-link).
 *
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {boolean} emailLink Whether the portal offers the e-mail link.
 * @return {Record<string, string>} The label per mode.
 */
function modeLabels(t, emailLink) {
	const labels = {
		nextcloud: t('Log in with your account'),
		local: t('Log in'),
		oidc: t('Log in'),
		digid: t('Log in with {provider}', { provider: 'DigiD' }),
		eherkenning: t('Log in with {provider}', { provider: 'eHerkenning' }),
		eidas: t('Log in with {provider}', { provider: 'eIDAS' }),
	}
	if (emailLink === true) {
		labels['email-link'] = t('Send me a sign-in link')
	}
	return labels
}

/**
 * The portal slug and the page to come back to, as query parts.
 *
 * THE PORTAL SLUG TRAVELS WITH THE LINK, and leaving it off is not a
 * cosmetic omission. The auth edge falls back to resolving the portal by
 * HOST, and several portals share one host on a development instance, so a
 * sign-in started from portal B arrived at the edge looking like portal A
 * and was refused with `mode_not_offered`. Measured: the button led to a
 * 404 whose body named a mode the visitor's portal does in fact offer.
 *
 * @param {object|null} site The portal record.
 * @return {{slug: string, scope: string, returnTo: string, query: string}} The parts, and both joined.
 */
function returnQuery(site) {
	const slug = site && site.slug ? String(site.slug) : ''
	const scope = slug ? `portal=${encodeURIComponent(slug)}` : ''
	// Come back to the page the visitor was actually on, not the portal root.
	const returnTo =
		typeof window !== 'undefined' && window.location
			? `returnTo=${encodeURIComponent(window.location.pathname + window.location.search)}`
			: ''
	return { slug, scope, returnTo, query: [scope, returnTo].filter(Boolean).join('&') }
}

/**
 * A MODE IS NOT A PROVIDER. The auth edge knows the providers digid,
 * eherkenning, eidas and generic. `local` and `oidc` are portal modes that
 * both sign in through the organisation's generic OIDC broker, so a link
 * carrying `provider=oidc` was refused whatever the organisation had
 * configured (#802).
 */
const PROVIDER_OF_MODE = { local: 'generic', oidc: 'generic' }

/**
 * The route of one way in. The card a portal wrote for it
 * (site-chrome-follows-the-design) names who it is for, what it opens, the
 * button's text and a hint; a way in without one keeps the standard label.
 *
 * @param {string} mode The mode.
 * @param {{site: object, authBase: string, slug: string, query: string, label: string}} ctx The portal, the auth edge base, the query and the standard label.
 * @return {object} The route.
 */
function modeRoute(mode, { site, authBase, slug, query, label }) {
	const cards = site?.authentication?.modeLabels || {}
	return {
		key: mode,
		mode,
		label: cards[mode]?.button || label,
		...(cards[mode] ? { card: cards[mode] } : {}),
		// Nextcloud's own reset, reached from the account route's card
		// (password-reset-from-the-sign-in-page REQ-PWR-001).
		...(mode === 'nextcloud' && site?.lostPasswordUrl
			? { lostPasswordUrl: String(site.lostPasswordUrl) }
			: {}),
		// Nextcloud's own registration form, served by the site config only
		// when the portal names a resident group the registration app fills
		// (nextcloud-account-registration REQ-NCL-005). Also on a demo: the
		// one-click cards sit beside this route, they do not replace it.
		// Never on another mode: the e-mail link opens no registration.
		...(mode === 'nextcloud' && site?.registerUrl
			? { registerUrl: String(site.registerUrl) }
			: {}),
		...(mode === 'email-link'
			? { form: 'email-link', authBase, portal: slug }
			: {}),
		href: modeHref(mode, { authBase, query }),
	}
}

/**
 * The address a way in starts at: none for the e-mail link's form.
 *
 * @param {string} mode The mode.
 * @param {{authBase: string, query: string}} ctx The auth edge base and the query.
 * @return {string} The address.
 */
function modeHref(mode, { authBase, query }) {
	if (mode === 'email-link') {
		return ''
	}
	if (mode === 'nextcloud') {
		return `${authBase}/session/nextcloud${query ? `?${query}` : ''}`
	}
	return `${authBase}/session/oidc/start?provider=${encodeURIComponent(
		PROVIDER_OF_MODE[mode] || mode,
	)}${query ? `&${query}` : ''}`
}

/**
 * One demo card per example resident. A shell from before there were
 * several names only the set: one card for its first resident.
 *
 * @param {Array<object>|unknown} demoResidents The residents of the example set.
 * @param {{authBase: string, t: (key: string, vars?: object) => string, exampleResident: string, scope: string, returnTo: string}} ctx The auth edge base, the translator, the set and the query parts.
 * @return {Array<object>} The routes.
 */
function demoRoutes(demoResidents, ctx) {
	const residents = Array.isArray(demoResidents)
		? demoResidents.filter((r) => r && typeof r.key === 'string' && r.key)
		: []
	const offered = residents.length ? residents : [{ key: '', name: '' }]
	return offered.map((resident) => demoRoute(resident, ctx))
}

/**
 * The one-click card of one example resident.
 *
 * @param {{key: string, name?: string, card?: object}} resident The resident.
 * @param {{authBase: string, t: (key: string, vars?: object) => string, exampleResident: string, scope: string, returnTo: string}} ctx The auth edge base, the translator, the set and the query parts.
 * @return {object} The route.
 */
function demoRoute(resident, { authBase, t, exampleResident, scope, returnTo }) {
	const name = String(resident.name || '')
	const card = resident.card || {}
	const demoQuery = [
		`id=${encodeURIComponent(exampleResident)}`,
		resident.key ? `resident=${encodeURIComponent(resident.key)}` : '',
		scope,
		returnTo,
	]
		.filter(Boolean)
		.join('&')
	return {
		key: `example-resident:${resident.key}`,
		mode: 'example-resident',
		label:
			card.button
			|| (name ? t('Log in as {name}', { name }) : t('Log in as the example resident')),
		card: {
			title: card.title || name || t('Example resident'),
			...(card.text ? { text: card.text } : {}),
		},
		demo: true,
		href: `${authBase}/session/example-resident?${demoQuery}`,
	}
}
