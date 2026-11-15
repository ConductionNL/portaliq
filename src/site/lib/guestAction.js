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
 * @spec openspec/specs/portal-guest-actions/spec.md#requirement-a-signed-link-opens-a-page-for-its-one-act-without-an-account-req-gst-002
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
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T06
 */
export function guestStrings(lang) {
	return String(lang || '')
		.toLowerCase()
		.startsWith('en')
		? STRINGS.en
		: STRINGS.nl
}

/**
 * Read `#guest/<app>/<action>/<token>` once and remove it from the address
 * bar. Any `#guest/` fragment is removed, also a malformed one.
 *
 * @param {object} location `window.location`.
 * @param {object} history  `window.history`.
 * @return {object|null} `{app, action, token}`, or null.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T03
 */
export function takeGuestLink(location, history) {
	const hash = String((location && location.hash) || '')
	if (!hash.startsWith(PREFIX)) {
		return null
	}

	clearAddressFragment(location, history)
	return guestLinkOf(hash.slice(PREFIX.length))
}

/**
 * Take the fragment off the address bar, keeping the path and the query.
 *
 * @param {object} location `window.location`.
 * @param {object} history  `window.history`.
 * @return {void}
 */
function clearAddressFragment(location, history) {
	history.replaceState(
		null,
		'',
		String(location.pathname || '') + String(location.search || ''),
	)
}

/**
 * The link a `<app>/<action>/<token>` fragment names, when each part is well
 * formed.
 *
 * @param {string} rest The fragment after `#guest/`.
 * @return {object|null} `{app, action, token}`, or null.
 */
function guestLinkOf(rest) {
	const parts = rest.split('/')
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
		const response = await fetchImpl(url, jsonPostInit(body))
		const parsed = await jsonBodyOf(response)
		return { ok: response.ok === true, status: response.status, body: parsed }
	} catch {
		return { ok: false, status: 0, body: {} }
	}
}

/**
 * @param {object} body The body.
 * @return {object} The request: a JSON POST, without a bearer.
 */
function jsonPostInit(body) {
	return {
		method: 'POST',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
		},
		body: JSON.stringify(body),
	}
}

/**
 * @param {Response|object} response A guest route's response.
 * @return {Promise<object>} Its JSON body, or an empty one when it has none.
 */
async function jsonBodyOf(response) {
	try {
		return (await response.json()) || {}
	} catch {
		return {}
	}
}

/**
 * The route of one guest action.
 *
 * @param {{authBase: string, link: object}} call The portal API base (`.../portal/api`) and `{app, action}`.
 * @return {string} The route.
 */
function routeOf(call) {
	const { authBase, link } = call
	return `${authBase}/guest/${encodeURIComponent(link.app)}/${encodeURIComponent(link.action)}`
}

/**
 * The body for a route: the fields, then the token and the serving portal.
 *
 * @param {{link: object, portal: string}} call The link and the serving portal's slug, or ''.
 * @param {object} fields The visitor's field values.
 * @return {object} The body.
 */
function bodyOf(call, fields) {
	const body = { ...fields, token: call.link.token }
	if (call.portal) {
		body.portal = call.portal
	}

	return body
}

/**
 * Ask what the link is for.
 *
 * @param {{authBase: string, link: object, portal: string}} call The portal API base, `{app, action, token}` and the serving portal's slug, or ''.
 * @param {typeof fetch} fetchImpl `fetch`, replaceable in tests.
 * @return {Promise<object>} `{ok, status, body}`.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T04
 */
export function guestPreview(call, fetchImpl = fetch) {
	return post(`${routeOf(call)}/preview`, bodyOf(call, {}), fetchImpl)
}

/**
 * Do the confirmed act.
 *
 * @param {{authBase: string, link: object, portal: string, fields: object}} call The portal API base, `{app, action, token}`, the serving portal's slug or '', and the declared fields' values.
 * @param {typeof fetch} fetchImpl `fetch`, replaceable in tests.
 * @return {Promise<object>} `{ok, status, body}`.
 *
 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T04
 */
export function guestAct(call, fetchImpl = fetch) {
	return post(routeOf(call), bodyOf(call, call.fields || {}), fetchImpl)
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
 * @spec openspec/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
 */
export function previewState(answer, strings) {
	const body = answerBody(answer)
	const state = declarationState(declaredAction(answer), strings)
	if (isOk(answer)) {
		return { ...state, ...availability(body, strings) }
	}

	if (answer?.status === 404) {
		return state
	}

	// The route wraps the app's own answer as `preview`; a bare answer
	// (a 400 or 502 of the route itself) carries its message on top.
	return { ...state, usable: false, reason: failureMessage(body) || strings.unusable }
}

/**
 * @param {object|null} answer `{ok, status, body}` of a guest route.
 * @return {object} Its body, or an empty one.
 */
function answerBody(answer) {
	return (answer && answer.body) || {}
}

/**
 * @param {object|null} answer `{ok, status, body}` of a guest route.
 * @return {boolean} Whether the route answered with success.
 */
function isOk(answer) {
	return Boolean(answer && answer.ok)
}

/**
 * @param {object|null} answer `{ok, status, body}` of the preview route.
 * @return {object} The action's declaration from a good answer, or an empty one.
 */
function declaredAction(answer) {
	return (isOk(answer) && answerBody(answer).action) || {}
}

/**
 * The state the page starts from: usable, with the declaration's button
 * texts and fields.
 *
 * @param {object} action  The action's declaration.
 * @param {object} strings The page's strings.
 * @return {object} `{usable, summary, reason, label, confirmText, fields, fieldConfigs}`.
 */
function declarationState(action, strings) {
	return {
		usable: true,
		summary: '',
		reason: '',
		label: text(action.label) || strings.continue,
		confirmText: text(action.confirmText) || strings.confirm,
		fields: declaredFields(action),
		fieldConfigs: declaredFieldConfigs(action),
	}
}

/**
 * @param {object} action The action's declaration.
 * @return {Array<string>} The names of the fields it declares.
 */
function declaredFields(action) {
	return Array.isArray(action.fields)
		? action.fields.filter((f) => typeof f === 'string')
		: []
}

/**
 * @param {object} action The action's declaration.
 * @return {object} The configuration of its fields, or none.
 */
function declaredFieldConfigs(action) {
	return action.fieldConfigs && typeof action.fieldConfigs === 'object'
		? action.fieldConfigs
		: {}
}

/**
 * What a good preview says: its summary, and the reason when the action is
 * not available.
 *
 * @param {object} body    The preview route's body.
 * @param {object} strings The page's strings.
 * @return {object} `{summary}`, or `{summary, usable: false, reason}`.
 */
function availability(body, strings) {
	const preview = body.preview || {}
	const summary = text(preview.summary)
	if (preview.available !== false) {
		return { summary }
	}

	return { summary, usable: false, reason: text(preview.reason) || strings.unusable }
}

/**
 * @param {object} body The failed route's body.
 * @return {string} The app's message inside `preview`, else the route's own, or ''.
 */
function failureMessage(body) {
	return text((body.preview || {}).message) || text(body.message)
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
 * @spec openspec/specs/portal-guest-actions/spec.md#requirement-the-page-shows-the-apps-answer-req-gst-004
 */
export function actOutcome(answer, declaration, strings) {
	const body = answerBody(answer)
	if (isOk(answer)) {
		const url = text(body.redirectUrl)
		if (url.startsWith('https://')) {
			return { kind: 'redirect', url, message: strings.redirecting }
		}

		return { kind: 'done', message: doneMessage(body, declaration) || strings.done }
	}

	return { kind: 'refused', message: text(body.message) || strings.unusable }
}

/**
 * @param {object} body The act route's body.
 * @param {object|null} declaration The action's declaration from the preview.
 * @return {string} The app's message, else the action's success text, or ''.
 */
function doneMessage(body, declaration) {
	return text(body.message) || text((declaration || {}).successText)
}
