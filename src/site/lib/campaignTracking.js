/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * First-party, portal-scoped UTM capture for landing pages
 * (landing-page-provisioning). No third-party script, no cookie — a
 * `sessionStorage` entry keyed per portal, so it never crosses a visitor's
 * tabs to a DIFFERENT portal on the same browser.
 *
 * FIRST TOUCH is written once per session and never overwritten — it answers
 * "what brought this visitor here the first time". LAST TOUCH is overwritten
 * on every landing that carries UTM parameters — it answers "what brought
 * them back for the visit that actually converted". Both are read at submit
 * time by `FormBlock.vue` and sent as part of the (whitelisted) submission
 * body.
 *
 * THESE VALUES ARE ADVISORY, NOT SECURITY-RELEVANT (ADR-005). A visitor
 * could edit them in devtools before submitting; the worst consequence is a
 * wrong campaign-report row, never an authorization bypass — `submittedAt`
 * and `nonce`, by contrast, are stamped server-side and never trusted from
 * here.
 */

const UTM_KEYS = ['campaign', 'source', 'medium', 'term', 'content']
const QUERY_PARAM_PREFIX = 'utm_'

/**
 * @param {string} portal The portal slug this capture is scoped to.
 * @return {string} The first-touch sessionStorage key.
 */
function firstTouchKey(portal) {
	return `portaliq:campaign:${portal}:first`
}

/**
 * @param {string} portal The portal slug this capture is scoped to.
 * @return {string} The last-touch sessionStorage key.
 */
function lastTouchKey(portal) {
	return `portaliq:campaign:${portal}:last`
}

/**
 * @param {string} portal The portal slug this capture is scoped to.
 * @return {string} The referrer sessionStorage key.
 */
function referrerKey(portal) {
	return `portaliq:campaign:${portal}:referrer`
}

/**
 * Read `utm_*` query parameters off a URL's search string.
 *
 * @param {string} search `location.search`, or an equivalent query string.
 * @return {object|null} `{campaign, source, medium, term, content}`, or null when NO utm_* parameter is present.
 */
function readUtmFromQuery(search) {
	const params = new URLSearchParams(search || '')
	const touch = {}
	let hasAny = false

	for (const key of UTM_KEYS) {
		const value = params.get(QUERY_PARAM_PREFIX + key)
		if (value) {
			touch[key] = value
			hasAny = true
		} else {
			touch[key] = null
		}
	}

	return hasAny ? touch : null
}

/**
 * Safely read a JSON object out of sessionStorage. Never throws — a private
 * window, cleared site data, or a browser blocking storage must not break
 * the page.
 *
 * @param {string} key The sessionStorage key.
 * @return {object|null}
 */
function readStored(key) {
	try {
		const raw = window.sessionStorage.getItem(key)
		return raw ? JSON.parse(raw) : null
	} catch {
		return null
	}
}

/**
 * Safely write a JSON object to sessionStorage. Never throws.
 *
 * @param {string} key The sessionStorage key.
 * @param {object} value The value to store.
 * @return {void}
 */
function writeStored(key, value) {
	try {
		window.sessionStorage.setItem(key, JSON.stringify(value))
	} catch {
		// Storage unavailable — the capture degrades to "no attribution
		// recorded", never a broken page.
	}
}

/**
 * Capture the current landing's UTM parameters into first/last touch,
 * scoped to one portal. Call once per page load, before the form is
 * submitted.
 *
 * @param {string} portal The serving portal's slug.
 * @param {string} [search] `location.search` (injectable for tests).
 * @return {void}
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-utm-capture-is-first-party-portal-scoped-and-honest-about-being-advisory
 */
export function captureLanding(portal, search = window.location.search) {
	if (!portal) {
		return
	}

	// The referrer captured at FIRST touch is what this session's attribution
	// answers "how did they find us" with — document.referrer on a later,
	// same-session visit usually points back at the portal's OWN pages
	// (internal navigation), which would silently overwrite a real external
	// referrer with a useless self-reference.
	if (!readStored(referrerKey(portal))) {
		writeStored(referrerKey(portal), { value: document.referrer || '' })
	}

	const touch = readUtmFromQuery(search)
	if (!touch) {
		return
	}

	if (!readStored(firstTouchKey(portal))) {
		writeStored(firstTouchKey(portal), touch)
	}

	writeStored(lastTouchKey(portal), touch)
}

/**
 * @param {string} portal The serving portal's slug.
 * @return {object} `{campaign, source, medium, term, content}`, all null when nothing was ever captured.
 */
export function firstTouch(portal) {
	return (
		readStored(firstTouchKey(portal)) || {
			campaign: null,
			source: null,
			medium: null,
			term: null,
			content: null,
		}
	)
}

/**
 * A touch as the submission carries it: only the parameters that were
 * captured, or null when none were.
 *
 * The object store validates nested values against the schema, and a
 * `campaign: null` on a string property fails the whole write; a visitor
 * who arrived without any campaign parameter could not submit the form
 * at all. Null for the object as a whole is what the store reads as
 * "nothing here".
 *
 * @param {object|null} touch A first or last touch, as `firstTouch()` returns it.
 * @return {object|null} The non-empty parameters, or null.
 *
 * @spec openspec/specs/landing-page-provisioning/spec.md#requirement-utm-capture-is-first-party-portal-scoped-and-honest-about-being-advisory
 */
export function compactTouch(touch) {
	if (!touch || typeof touch !== 'object') {
		return null
	}
	const out = {}
	Object.keys(touch).forEach((key) => {
		const value = touch[key]
		if (typeof value === 'string' && value !== '') {
			out[key] = value
		}
	})
	return Object.keys(out).length === 0 ? null : out
}

/**
 * @param {string} portal The serving portal's slug.
 * @return {object} `{campaign, source, medium, term, content}`, all null when nothing was ever captured.
 */
export function lastTouch(portal) {
	return (
		readStored(lastTouchKey(portal)) || {
			campaign: null,
			source: null,
			medium: null,
			term: null,
			content: null,
		}
	)
}

/**
 * @param {string} portal The serving portal's slug.
 * @return {string} The referrer captured at first touch this session; falls back to the LIVE `document.referrer` when nothing was ever captured (e.g. `captureLanding` was never called).
 */
export function capturedReferrer(portal) {
	const stored = readStored(referrerKey(portal))
	if (stored && typeof stored.value === 'string') {
		return stored.value
	}

	return document.referrer || ''
}
