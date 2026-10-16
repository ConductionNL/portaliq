/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Asking for a higher login (site-member-voting-record-and-confidential-papers
 * REQ-SCR-004, design D5).
 *
 * The aggregate names, under `stepUp`, the collections the resident's login is
 * too low for: `{app, collection, label, minTrust}`, never a row or a count.
 * Each entry is a page of its own in the signed-in area (portalNav.js
 * stepUpEntries); this module picks the sign-in routes that reach the level
 * and words which login that is.
 */

/**
 * The sign-in modes that carry a legal identity, and so reach a level above
 * `low`: DigiD for a resident, eHerkenning for a company, eIDAS for a European
 * citizen. An account, an e-mail link or a demo resident never does.
 */
export const STEP_UP_MODES = Object.freeze(['digid', 'eherkenning', 'eidas'])

/** The provider name each step-up mode shows in the sentence. */
const PROVIDER_NAMES = Object.freeze({
	digid: 'DigiD',
	eherkenning: 'eHerkenning',
	eidas: 'eIDAS',
})

/**
 * Set the `returnTo` of a sign-in link to the page on screen. The routes are
 * built once, when the site loads, so their `returnTo` is the page the
 * visitor arrived on, not the page they asked a higher login from.
 *
 * @param {string} href The sign-in link.
 * @param {string} back The in-site path to come back to.
 * @return {string} The link with that `returnTo`.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
 */
export function withReturnTo(href, back) {
	const link = String(href || '')
	if (link === '' || typeof back !== 'string' || !back.startsWith('/')) {
		return link
	}
	const hashAt = link.indexOf('#')
	const hash = hashAt === -1 ? '' : link.slice(hashAt)
	const bare = hashAt === -1 ? link : link.slice(0, hashAt)
	const queryAt = bare.indexOf('?')
	const path = queryAt === -1 ? bare : bare.slice(0, queryAt)
	const params = (queryAt === -1 ? '' : bare.slice(queryAt + 1))
		.split('&')
		.filter((part) => part !== '' && !part.startsWith('returnTo='))
	params.push(`returnTo=${encodeURIComponent(back)}`)
	return `${path}?${params.join('&')}${hash}`
}

/**
 * The portal's sign-in routes that reach a step-up level, each coming back to
 * `back` after signing in.
 *
 * @param {Array<object>} routes The portal's sign-in routes (authApi signInRoutes).
 * @param {string} back The in-site path to come back to.
 * @return {Array<object>} The routes, in the portal's order.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
 */
export function stepUpWays(routes, back) {
	return (Array.isArray(routes) ? routes : [])
		.filter((way) => way && STEP_UP_MODES.includes(way.mode) && way.href)
		.map((way) => ({ ...way, href: withReturnTo(way.href, back) }))
}

/**
 * The providers of those routes as one phrase: "DigiD", "DigiD or
 * eHerkenning", "DigiD, eHerkenning or eIDAS".
 *
 * @param {Array<object>} ways The step-up routes.
 * @param {(key: string, vars?: object) => string} t The site translator.
 * @return {string} The phrase, or '' without routes.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t6
 */
export function providerPhrase(ways, t) {
	const names = [
		...new Set(
			(ways || []).map((way) => PROVIDER_NAMES[way.mode]).filter(Boolean),
		),
	]
	if (names.length < 2) {
		return names[0] || ''
	}
	return t('{first} or {last}', {
		first: names.slice(0, -1).join(', '),
		last: names[names.length - 1],
	})
}
