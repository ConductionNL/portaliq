// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The guest page for signed links (identity-guest-page-for-signed-links).
 *
 * A person without a portal account opens a link a contributing app signed
 * and mailed them: `/apps/portaliq/site#guest/<app>/<action>/<token>`. The
 * token rides in the fragment so it never reaches a server log or a referrer.
 * This file reads it once, posts it to the guest routes, and turns each
 * answer into what the page shows. Pure where it can be, so a plain node test
 * asserts it; the network calls take their `fetch` as a parameter.
 *
 * Portaliq checks nothing about the token: the app that signed it does, on
 * the forward. So nothing here decides whether the act is allowed.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md#requirement-a-signed-link-opens-a-page-for-its-one-act-without-an-account-req-gst-002
 */

const PREFIX = '#guest/'
const APP_ID = /^[a-z0-9][a-z0-9_-]*$/
const ACTION_ID = /^[a-zA-Z0-9][a-zA-Z0-9_-]*$/
const TOKEN = /^[A-Za-z0-9._~%+=-]+$/

const STRINGS = Object.freeze({
	en: Object.freeze({
		title: 'Your link',
		loading: 'Loading…',
		continue: 'Continue',
		confirm: 'Do you want to do this now? You cannot undo it.',
		confirmYes: 'Yes, continue',
		cancel: 'Cancel',
		busy: 'One moment…',
		done: 'Done. You can close this page.',
		unusable: 'This link cannot be used.',
		redirecting: 'Taking you to the payment page',
	}),
	nl: Object.freeze({
		title: 'Uw link',
		loading: 'Bezig met laden…',
		continue: 'Doorgaan',
		confirm: 'Wilt u dit nu doen? U kunt het niet ongedaan maken.',
		confirmYes: 'Ja, doorgaan',
		cancel: 'Annuleren',
		busy: 'Een moment…',
		done: 'Gelukt. U kunt deze pagina sluiten.',
		unusable: 'Deze link kan niet worden gebruikt.',
		redirecting: 'U gaat naar de betaalpagina',
	}),
})

/**
 * The page's strings in the visitor's language: English for `en`, Dutch
 * otherwise, as the rest of the site.
 *
 * @param {string} lang The document language.
 * @return {object} The strings.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T06
 */
export function guestStrings(lang) {
	return String(lang || '').toLowerCase().startsWith('en') ? STRINGS.en : STRINGS.nl
}

/**
 * Read `#guest/<app>/<action>/<token>` once and remove it from the address
 * bar. Any `#guest/` fragment is removed, also a malformed one.
 *
 * @param {object} location `window.location`.
 * @param {object} history  `window.history`.
 * @return {object|null} `{app, action, token}`, or null.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T03
 */
export function takeGuestLink(location, history) {
	const hash = String((location && location.hash) || '')
	if (!hash.startsWith(PREFIX)) {
		return null
	}

	history.replaceState(null, '', String(location.pathname || '') + String(location.search || ''))

	const parts = hash.slice(PREFIX.length).split('/')
	if (parts.length !== 3) {
		return null
	}

	const [app, action, token] = parts
	if (!APP_ID.test(app) || !ACTION_ID.test(action) || !TOKEN.test(token)) {
		return null
	}

	return { app, action, token }
}

/**
 * POST one body to a guest route. Never throws: a network failure is
 * `{ok: false, status: 0, body: {}}`. No bearer is ever sent.
 *
 * @param {string}   url       The route.
 * @param {object}   body      The body.
 * @param {Function} fetchImpl `fetch`.
 * @return {Promise<object>} `{ok, status, body}`.
 */
async function post(url, body, fetchImpl) {
	try {
		const response = await fetchImpl(url, {
			method: 'POST',
			headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
			body: JSON.stringify(body),
		})
		let parsed = {}
		try {
			parsed = (await response.json()) || {}
		} catch {
			parsed = {}
		}

		return { ok: response.ok === true, status: response.status, body: parsed }
	} catch {
		return { ok: false, status: 0, body: {} }
	}
}

/**
 * The route of one guest action.
 *
 * @param {string} authBase The portal API base (`.../portal/api`).
 * @param {object} link     `{app, action}`.
 * @return {string} The route.
 */
function routeOf(authBase, link) {
	return `${authBase}/guest/${encodeURIComponent(link.app)}/${encodeURIComponent(link.action)}`
}

/**
 * The body for a route: the fields, then the token and the serving portal.
 *
 * @param {object} fields The visitor's field values.
 * @param {string} token  The signed token.
 * @param {string} portal The serving portal's slug, or ''.
 * @return {object} The body.
 */
function bodyOf(fields, token, portal) {
	const body = { ...fields, token }
	if (portal) {
		body.portal = portal
	}

	return body
}

/**
 * Ask what the link is for.
 *
 * @param {string}   authBase  The portal API base.
 * @param {object}   link      `{app, action, token}`.
 * @param {string}   portal    The serving portal's slug, or ''.
 * @param {Function} fetchImpl `fetch`, replaceable in tests.
 * @return {Promise<object>} `{ok, status, body}`.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T04
 */
export function guestPreview(authBase, link, portal, fetchImpl = fetch) {
	return post(`${routeOf(authBase, link)}/preview`, bodyOf({}, link.token, portal), fetchImpl)
}

/**
 * Do the confirmed act.
 *
 * @param {string}   authBase  The portal API base.
 * @param {object}   link      `{app, action, token}`.
 * @param {object}   fields    The declared fields' values.
 * @param {string}   portal    The serving portal's slug, or ''.
 * @param {Function} fetchImpl `fetch`, replaceable in tests.
 * @return {Promise<object>} `{ok, status, body}`.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/tasks.md#T04
 */
export function guestAct(authBase, link, fields, portal, fetchImpl = fetch) {
	return post(routeOf(authBase, link), bodyOf(fields || {}, link.token, portal), fetchImpl)
}

/**
 * A string, or ''.
 *
 * @param {*} value The value.
 * @return {string} The value when it is a non-empty string.
 */
function text(value) {
	return typeof value === 'string' && value.trim() !== '' ? value : ''
}

/**
 * What the page shows after the preview. A 404 means the action has no
 * preview (or does not exist; the route does not say which): the page then
 * offers a plain "Continue" and the act's own answer decides.
 *
 * @param {object} answer  `{ok, status, body}` of the preview route.
 * @param {object} strings The page's strings.
 * @return {object} `{usable, summary, reason, label, confirmText, fields, fieldConfigs}`.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
 */
export function previewState(answer, strings) {
	const body = (answer && answer.body) || {}
	const action = (answer && answer.ok && body.action) || {}
	const state = {
		usable: true,
		summary: '',
		reason: '',
		label: text(action.label) || strings.continue,
		confirmText: text(action.confirmText) || strings.confirm,
		fields: Array.isArray(action.fields) ? action.fields.filter((f) => typeof f === 'string') : [],
		fieldConfigs: action.fieldConfigs && typeof action.fieldConfigs === 'object' ? action.fieldConfigs : {},
	}

	if (answer && answer.ok) {
		const preview = body.preview || {}
		state.summary = text(preview.summary)
		if (preview.available === false) {
			state.usable = false
			state.reason = text(preview.reason) || strings.unusable
		}

		return state
	}

	if (answer && answer.status === 404) {
		return state
	}

	return { ...state, usable: false, reason: text(body.message) || strings.unusable }
}

/**
 * What the page does after the act: follow an `https` checkout address, show
 * the app's message (or the action's success text), or show the refusal.
 *
 * @param {object} answer      `{ok, status, body}` of the act route.
 * @param {object} declaration The action's declaration from the preview.
 * @param {object} strings     The page's strings.
 * @return {object} `{kind: 'redirect'|'done'|'refused', message, url?}`.
 *
 * @spec openspec/changes/identity-guest-page-for-signed-links/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
 */
export function actOutcome(answer, declaration, strings) {
	const body = (answer && answer.body) || {}
	if (answer && answer.ok) {
		const url = text(body.redirectUrl)
		if (url.startsWith('https://')) {
			return { kind: 'redirect', url, message: strings.redirecting }
		}

		return {
			kind: 'done',
			message: text(body.message) || text((declaration || {}).successText) || strings.done,
		}
	}

	return { kind: 'refused', message: text(body.message) || strings.unusable }
}
