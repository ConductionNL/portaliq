// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The co-signer's page of a form that needs a second signature
// (resident-identity-in-forms REQ-RIF-003, board MedeOndertekenen).
//
// `/mede-ondertekenen` opens on every portal without a CMS page. The co-signer
// types the code from the invitation, signs in with DigiD, reads the request
// and co-signs or refuses. The code is kept in the tab's session storage over
// the DigiD round trip, never in the address bar, so it does not end up in the
// history or a referrer.
//
// Imports nothing, so tests/site-cosign.spec.mjs runs it as node.
//
// @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003

/** The in-site route of the co-sign page. */
export const COSIGN_ROUTE = '/mede-ondertekenen'

/** Where the code waits during the DigiD round trip. */
export const CODE_KEY = 'portaliq.cosign.code'

/** Six letters or digits, as PortalCosign::normalise() reads a code. */
const CODE = /^[A-Z0-9]{6}$/

/** The answer status for each refusal the server names. */
const REFUSALS = {
	sign_in_required: 'signIn',
	no_subject: 'signIn',
	unknown: 'notFound',
	expired: 'expired',
	same_subject: 'sameSubject',
	not_confirmed: 'notConfirmed',
}

/**
 * Whether a route is the co-sign page.
 *
 * @param {string} route The in-site route.
 * @return {boolean}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function isCosignRoute(route) {
	const value = String(route || '').replace(/\/$/, '')
	return value === COSIGN_ROUTE
}

/**
 * A typed code in the form the mail shows it, `7RQ-K2M`, or '' when it cannot be one.
 *
 * Case, spaces and the dash do not matter.
 *
 * @param {string} typed What the co-signer typed.
 * @return {string}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function normaliseCode(typed) {
	const bare = String(typed || '').toUpperCase().replace(/[^A-Z0-9]/g, '')
	return CODE.test(bare) ? `${bare.slice(0, 3)}-${bare.slice(3)}` : ''
}

/**
 * The tab's session storage, or null where it cannot be used.
 *
 * @param {object} [storage] A stand-in in a test.
 * @return {object|null}
 */
function storageOf(storage) {
	if (storage) {
		return storage
	}
	try {
		return typeof window !== 'undefined' && window.sessionStorage ? window.sessionStorage : null
	} catch {
		return null
	}
}

/**
 * Keep the code over the DigiD round trip.
 *
 * @param {string} code The normalised code.
 * @param {object} [storage] A stand-in in a test.
 * @return {boolean} Whether it was kept.
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function rememberCode(code, storage) {
	try {
		storageOf(storage)?.setItem(CODE_KEY, code)
		return true
	} catch {
		return false
	}
}

/**
 * The code kept before the DigiD round trip, or ''.
 *
 * @param {object} [storage] A stand-in in a test.
 * @return {string}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function recallCode(storage) {
	try {
		return normaliseCode(storageOf(storage)?.getItem(CODE_KEY) || '')
	} catch {
		return ''
	}
}

/**
 * Drop the kept code once the co-signer answered.
 *
 * @param {object} [storage] A stand-in in a test.
 * @return {void}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function forgetCode(storage) {
	try {
		storageOf(storage)?.removeItem(CODE_KEY)
	} catch {
		// Nothing kept, nothing to drop.
	}
}

/**
 * The DigiD sign-in that comes back to the co-sign page.
 *
 * @param {string} authBase The auth edge base (`…/portal/api`).
 * @param {string} portal The portal slug, or ''.
 * @return {string} The address.
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function digidHref(authBase, portal) {
	const query = new URLSearchParams({ provider: 'digid' })
	if (portal) {
		query.set('portal', portal)
	}
	query.set('returnTo', returnTo())
	return `${String(authBase || '').replace(/\/$/, '')}/session/oidc/start?${query.toString()}`
}

/**
 * The page to come back to: where the co-signer is now.
 *
 * @return {string}
 */
function returnTo() {
	if (typeof window === 'undefined' || !window.location) {
		return COSIGN_ROUTE
	}
	return window.location.pathname + window.location.search
}

/**
 * The answer status for a response that is no success.
 *
 * @param {object} parsed The body, `{}` when it was not JSON.
 * @return {string}
 */
function refusalOf(parsed) {
	return REFUSALS[String(parsed?.error || '')] || 'error'
}

/**
 * The fetch and headers for one call.
 *
 * @param {{token?: string, fetchImpl?: typeof fetch}} conn The connection.
 * @param {boolean} json Whether the body is JSON.
 * @return {{call: typeof fetch, headers: object}}
 */
function caller(conn, json) {
	const headers = { Accept: 'application/json' }
	if (json) {
		headers['Content-Type'] = 'application/json'
	}
	if (conn.token) {
		headers.Authorization = `Bearer ${conn.token}`
	}
	const call = conn.fetchImpl || ((...args) => window.fetch(...args))
	return { call, headers }
}

/**
 * Open the request the code names, read-only.
 *
 * @param {{base: string, portal?: string, token?: string, fetchImpl?: typeof fetch}} conn The connection.
 * @param {string} code The normalised code.
 * @return {Promise<{status: string, submission: object|null}>} `ok` with the
 *   request, else `signIn`, `notFound`, `expired`, `sameSubject` or `error`.
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export async function openCosign(conn, code) {
	if (!conn.token) {
		return { status: 'signIn', submission: null }
	}
	const query = new URLSearchParams({ code })
	if (conn.portal) {
		query.set('portal', conn.portal)
	}
	try {
		const { call, headers } = caller(conn, false)
		const response = await call(`${String(conn.base || '').replace(/\/$/, '')}/cosign?${query.toString()}`, { headers })
		const parsed = await response.json().catch(() => ({}))
		if (!response.ok) {
			return { status: refusalOf(parsed), submission: null }
		}
		return { status: 'ok', submission: cosignView(parsed?.submission) }
	} catch {
		return { status: 'error', submission: null }
	}
}

/**
 * Co-sign or refuse.
 *
 * @param {{base: string, portal?: string, token?: string, fetchImpl?: typeof fetch}} conn The connection.
 * @param {{code: string, action: 'sign'|'refuse', confirmed?: boolean}} answer The answer.
 * @return {Promise<{status: string, reference: string}>} `signed` or `refused`, else the refusal.
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export async function answerCosign(conn, { code, action, confirmed = false }) {
	const body = action === 'sign' ? { code, confirmed: confirmed === true } : { code }
	if (conn.portal) {
		body.portal = conn.portal
	}
	try {
		const { call, headers } = caller(conn, true)
		const response = await call(`${String(conn.base || '').replace(/\/$/, '')}/cosign/${action === 'sign' ? 'sign' : 'refuse'}`, {
			method: 'POST',
			headers,
			body: JSON.stringify(body),
		})
		const parsed = await response.json().catch(() => ({}))
		if (!response.ok) {
			return { status: refusalOf(parsed), reference: '' }
		}
		return { status: String(parsed?.state || ''), reference: String(parsed?.reference || '') }
	} catch {
		return { status: 'error', reference: '' }
	}
}

/**
 * What the review shows of a request: its reference, the deadline and the
 * answers as label/value lines, nothing else.
 *
 * @param {object|undefined} submission The server's read-only request.
 * @return {{reference: string, deadline: string, lines: Array<{label: string, value: string}>}}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function cosignView(submission) {
	const answers = submission && typeof submission.answers === 'object' && submission.answers !== null
		? submission.answers
		: {}
	const lines = Object.entries(answers)
		.filter(([, value]) => ['string', 'number', 'boolean'].includes(typeof value) && String(value) !== '')
		.filter(([, value]) => !String(value).startsWith('data:'))
		.map(([label, value]) => ({ label, value: String(value) }))
	return {
		reference: String(submission?.reference || ''),
		deadline: readableDay(submission?.deadline),
		lines,
	}
}

/**
 * A day as the boards write it, `22 oktober 2026`, or '' when it does not read.
 *
 * @param {string} value The moment, ISO 8601.
 * @param {string} [locale] The locale, Dutch by default.
 * @return {string}
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function readableDay(value, locale = 'nl-NL') {
	const moment = new Date(String(value || ''))
	if (!value || Number.isNaN(moment.getTime())) {
		return ''
	}
	return new Intl.DateTimeFormat(locale, {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'Europe/Amsterdam',
	}).format(moment)
}

/**
 * The line Mijn zaken shows for one of the resident's own held requests: it
 * waits for "{name}" until the deadline, or it was not co-signed and so not
 * sent. Such a request has no case until it is co-signed and delivered.
 *
 * @param {{reference?: string, state?: string, cosign?: {name?: string, deadline?: string}}} request A row of `/portal/api/cosign/held`.
 * @param {(key: string, vars?: object) => string} t The translator.
 * @param {string} [locale] The page language, `nl` by default.
 * @return {{reference: string, tone: string, title: string, sentence: string}|null} The line, or null for any other request.
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-a-form-can-require-a-second-person-to-co-sign-req-rif-003
 */
export function heldLine(request, t, locale = 'nl') {
	const reference = String(request?.reference || '')
	const title = t('Request {reference}', { reference })
	if (request?.state === 'not-cosigned') {
		return { reference, tone: 'error', title, sentence: t('Not co-signed, so it was not sent.') }
	}
	if (request?.state !== 'awaiting-cosign') {
		return null
	}
	const name = String(request.cosign?.name || '').trim()
	const waits = name
		? t('Waiting for co-signature by {name}.', { name })
		: t('Waiting for co-signature.')
	const day = readableDay(request.cosign?.deadline, String(locale).startsWith('nl') ? 'nl-NL' : 'en-GB')
	const sentence = day ? `${waits} ${t('This is possible until {date}.', { date: day })}` : waits
	return { reference, tone: 'info', title, sentence }
}
