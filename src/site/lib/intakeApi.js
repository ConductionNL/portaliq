/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public site's way into the intake endpoints
 * (portal-intake-form-as-an-object, T09).
 *
 * The four endpoints existed, were tested and had no caller: nothing in the
 * site, the portal SPA or the admin manifest asked for the catalogue, a form,
 * a submission or a status. These helpers are what the three intake blocks
 * call, kept free of Vue so the node tests drive them directly.
 *
 * Every call takes the fetch it uses as its last argument. The blocks pass
 * nothing and get `window.fetch`; the tests pass a recorder.
 */

/**
 * The URL of one intake endpoint.
 *
 * The portal slug travels when the site knows it: inside Nextcloud the site is
 * served for a named portal, and the endpoints would otherwise resolve the
 * portal from the host alone and answer 404.
 *
 * @param {string} base  The portal API base, e.g. `/apps/portaliq/portal/api`.
 * @param {string} path  The endpoint path, e.g. `/intake/catalogue`.
 * @param {object} query Query parameters; empty values are left out.
 * @return {string} The URL, relative to the origin.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
 */
export function intakeUrl(base, path, query = {}) {
	const params = new URLSearchParams()
	for (const [key, value] of Object.entries(query)) {
		if (value !== undefined && value !== null && value !== '') {
			params.set(key, String(value))
		}
	}

	const search = params.toString()
	return `${String(base || '').replace(/\/$/, '')}${path}${search ? `?${search}` : ''}`
}

/**
 * Request headers, with the bearer when the visitor is signed in.
 *
 * @param {string} token The portal bearer, or ''.
 * @param {boolean} json Whether a JSON body is sent.
 * @return {object} The headers.
 */
function headersFor(token, json = false) {
	const headers = { Accept: 'application/json' }
	if (json) {
		headers['Content-Type'] = 'application/json'
	}

	if (token) {
		headers.Authorization = `Bearer ${token}`
	}

	return headers
}

/**
 * The fetch to use: the one passed in, or the browser's.
 *
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl A fetch, or null.
 * @return {(url: string, init?: object) => Promise<object>} The fetch.
 */
function fetcher(fetchImpl) {
	return fetchImpl || ((...args) => window.fetch(...args))
}

/**
 * The published catalogue, by topic, as it stands right now.
 *
 * Read on every call and never kept: an entry withdrawn from the catalogue is
 * gone the next time the page is opened. An entry without a route cannot start
 * a form, so it is not offered, and a topic left with no entries is dropped.
 *
 * @param {string} base The portal API base.
 * @param {string} portal The portal slug, or ''.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<Array<{topic: string, entries: Array<object>}>>} The topics.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
 */
export async function fetchCatalogue(base, portal, fetchImpl = null) {
	const response = await fetcher(fetchImpl)(
		intakeUrl(base, '/intake/catalogue', { portal }),
		{
			headers: headersFor(''),
		},
	)
	if (!response.ok) {
		const error = new Error(`intake catalogue ${response.status}`)
		error.status = response.status
		throw error
	}

	const body = await response.json()
	const topics = Array.isArray(body?.topics) ? body.topics : []

	return topics
		.map((topic) => ({
			topic: String(topic?.topic || ''),
			entries: (Array.isArray(topic?.entries) ? topic.entries : []).filter(
				(entry) => typeof entry?.route === 'string' && entry.route !== '',
			),
		}))
		.filter((topic) => topic.entries.length > 0)
}

/**
 * The in-site route of the form page for one catalogue entry.
 *
 * The binding route carries slashes of its own (`aanvragen/verhuizing`), and
 * the site hands a page only ONE trailing segment when a route falls back to
 * its parent. So the binding route is encoded into that one segment.
 *
 * @param {string} formPage The route of the page that holds the form block.
 * @param {string} bindingRoute The entry's binding route.
 * @return {string} The in-site route.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
 */
export function formRouteFor(formPage, bindingRoute) {
	const page = `/${String(formPage || '').replace(/^\/+|\/+$/g, '')}`
	return `${page === '/' ? '' : page}/${encodeURIComponent(bindingRoute)}`
}

/**
 * The binding route a form block renders.
 *
 * A route the author placed on the block wins, so a form given its own page
 * cannot be swapped for another one through the URL. Otherwise it is the
 * segment the catalogue link carried. A segment that does not decode is no
 * route at all.
 *
 * @param {string} routeParam The trailing segment the site handed the page.
 * @param {string} fixedRoute The route the author placed on the block, or ''.
 * @return {string} The binding route, or ''.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
 */
export function bindingRouteFrom(routeParam, fixedRoute) {
	if (fixedRoute) {
		return String(fixedRoute)
	}

	try {
		return decodeURIComponent(String(routeParam || ''))
	} catch {
		return ''
	}
}

/**
 * The form a binding route resolves to, and what the page should show.
 *
 * States: `form` (render it), `external` (a start card naming the
 * destination), `noForm` (the binding resolves to no published form),
 * `signIn` (the form asks for a sign-in level the visitor does not have) and
 * `notFound` (no binding on that route).
 *
 * @param {string} base The portal API base.
 * @param {string} route The binding route.
 * @param {string} portal The portal slug, or ''.
 * @param {string} token The portal bearer, or ''.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<{state: string, render: object}>} The view.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-applicant-block-is-prefilled-from-the-signed-in-identity-only-req-pifo-003
 */
export async function loadForm(base, route, portal, token, fetchImpl = null) {
	const response = await fetcher(fetchImpl)(
		intakeUrl(base, '/intake/form', { route, portal }),
		{
			headers: headersFor(token),
		},
	)
	const body = await response.json().catch(() => ({}))

	if (response.status === 401 || response.status === 403) {
		return { state: 'signIn', render: body || {} }
	}

	if (!response.ok) {
		return { state: 'notFound', render: {} }
	}

	if (body?.kind === 'external' && body?.resolvesToNoForm !== true) {
		return { state: 'external', render: body }
	}

	if (body?.resolvesToNoForm === true) {
		return { state: 'noForm', render: body }
	}

	return { state: 'form', render: body || {} }
}

/**
 * The values a form starts with.
 *
 * The visitor's own details from the signed-in identity come first, then the
 * form's preset, then empty. An anonymous visitor gets no prefill from the
 * endpoint, so only presets remain.
 *
 * @param {Array<object>} fields The rendered fields.
 * @param {object} prefill The prefill the endpoint answered.
 * @return {object} Values keyed by field name.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-applicant-block-is-prefilled-from-the-signed-in-identity-only-req-pifo-003
 */
export function initialValues(fields, prefill) {
	const values = {}
	for (const field of Array.isArray(fields) ? fields : []) {
		const name = String(field?.name || '')
		if (name === '') {
			continue
		}

		const own =
			prefill && Object.hasOwn(prefill, name) ? prefill[name] : undefined
		if (own !== undefined && own !== null && own !== '') {
			values[name] = String(own)
		} else if (field.preset !== undefined && field.preset !== null) {
			values[name] = String(field.preset)
		} else {
			values[name] = ''
		}
	}

	return values
}

/**
 * Submit the answers for a reference.
 *
 * A 400 carries the per-field errors and is an answer, not a fault. Anything
 * else that is not a success is thrown, so the block can say the request was
 * not sent rather than pretend it was.
 *
 * @param {string} base The portal API base.
 * @param {string} route The binding route.
 * @param {object} answers The answers, keyed by field name.
 * @param {string} portal The portal slug, or ''.
 * @param {string} token The portal bearer, or ''.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<{reference: string, confirmationText: string, errors: object}>} The outcome.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
 */
export async function submitIntake(
	base,
	route,
	answers,
	portal,
	token,
	fetchImpl = null,
) {
	const body = { route, answers: answers || {} }
	if (portal) {
		body.portal = portal
	}

	const response = await fetcher(fetchImpl)(intakeUrl(base, '/intake/submit'), {
		method: 'POST',
		headers: headersFor(token, true),
		body: JSON.stringify(body),
	})
	const parsed = await response.json().catch(() => ({}))

	if (response.status === 400) {
		return { reference: '', confirmationText: '', errors: parsed?.errors || {} }
	}

	if (!response.ok || !parsed?.reference) {
		const error = new Error(`intake submit ${response.status}`)
		error.status = response.status
		throw error
	}

	return {
		reference: String(parsed.reference),
		confirmationText: String(parsed.confirmationText || ''),
		errors: {},
	}
}

/**
 * The state behind a reference, or null when there is none.
 *
 * @param {string} base The portal API base.
 * @param {string} reference The reference the visitor was given.
 * @param {string} portal The portal slug, or ''.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<object|null>} The status, or null.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
 */
export async function lookUpStatus(base, reference, portal, fetchImpl = null) {
	const trimmed = String(reference || '').trim()
	if (trimmed === '') {
		return null
	}

	const response = await fetcher(fetchImpl)(
		intakeUrl(base, '/intake/status', { reference: trimmed, portal }),
		{
			headers: headersFor(''),
		},
	)
	if (!response.ok) {
		return null
	}

	return response.json()
}

/**
 * The sentence a status reads as, and its tone.
 *
 * A queued request never claims a case: the case does not exist yet. A failed
 * create says so and names what to do, because a silent failure is the one
 * thing the reference page exists to prevent.
 *
 * @param {object|null} status The status, or null when none was found.
 * @return {{tone: string, sentence: string}} The view.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
 */
export function statusView(status) {
	if (!status) {
		return {
			tone: 'error',
			sentence:
				'We kunnen geen aanvraag vinden met dit kenmerk. Controleer het kenmerk en probeer het opnieuw.',
		}
	}

	const reference = String(status.reference || '')

	if (status.state === 'registered') {
		return {
			tone: 'success',
			sentence: `Uw aanvraag ${reference} is geregistreerd. U vindt de zaak terug in uw eigen omgeving.`,
		}
	}

	if (status.state === 'failed') {
		return {
			tone: 'error',
			sentence: `Uw aanvraag ${reference} is nog niet geregistreerd. Neem contact met ons op en noem uw kenmerk ${reference}.`,
		}
	}

	return {
		tone: 'info',
		sentence: `Uw aanvraag ${reference} is ontvangen en wordt verwerkt. Kijk later nog eens met hetzelfde kenmerk.`,
	}
}

/**
 * The sentences a declared fee is told in, or null when there is no fee.
 *
 * The amount is the one the case type declares, as the server rendered it;
 * the page never computes or sends an amount (intake-pay-on-submit
 * REQ-IPS-001, REQ-IPS-002).
 *
 * @param {{amount: string, currency?: string}|null} fee The render's fee.
 * @return {{costs: string, pay: string, signIn: string}|null} The sentences.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-fee-comes-from-the-case-type-req-ips-001
 */
export function feeText(fee) {
	const amount = Number(fee?.amount)
	if (!fee || !Number.isFinite(amount) || amount <= 0) {
		return null
	}

	let shown
	try {
		shown = new Intl.NumberFormat('nl-NL', {
			style: 'currency',
			currency: fee.currency || 'EUR',
		}).format(amount)
	} catch {
		return null
	}

	return {
		costs: `Deze aanvraag kost ${shown}.`,
		pay: `${shown} nu betalen`,
		signIn: `Log in om deze aanvraag te versturen. Er zijn leges van ${shown}.`,
	}
}

/**
 * Start paying the fee of the visitor's own request.
 *
 * Only the reference and the portal are sent: the amount is the server's.
 *
 * @param {string} base The portal API base.
 * @param {string} reference The request's reference.
 * @param {string} portal The portal slug, or ''.
 * @param {string} token The portal bearer.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<{checkoutUrl: string}>} Where to pay; thrown on any refusal.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-only-the-submitter-can-pay-once-req-ips-003
 */
export async function payIntake(base, reference, portal, token, fetchImpl = null) {
	const body = { reference }
	if (portal) {
		body.portal = portal
	}

	const response = await fetcher(fetchImpl)(intakeUrl(base, '/intake/pay'), {
		method: 'POST',
		headers: headersFor(token, true),
		body: JSON.stringify(body),
	})
	const parsed = await response.json().catch(() => ({}))
	if (
		!response.ok
		|| typeof parsed?.checkoutUrl !== 'string'
		|| parsed.checkoutUrl === ''
	) {
		const error = new Error(`intake pay ${response.status}`)
		error.status = response.status
		throw error
	}

	return { checkoutUrl: parsed.checkoutUrl }
}

/**
 * What the visitor reads about a request's payment, or null when nobody
 * started paying. The state is the one the server read from integriq's
 * payment record (REQ-IPS-005); a query string never decides it.
 *
 * @param {object|null} status The status answer.
 * @return {{sentence: string, canPay: boolean}|null} The view.
 *
 * @spec openspec/changes/intake-pay-on-submit/specs/portal-intake-payment/spec.md#requirement-the-result-is-read-from-the-payment-record-req-ips-005
 */
export function paymentView(status) {
	const state = status?.payment?.state
	if (!state) {
		return null
	}

	if (state === 'paid') {
		return { sentence: 'Betaald', canPay: false }
	}

	if (state === 'open') {
		return { sentence: 'Nog niet betaald', canPay: true }
	}

	if (state === 'failed') {
		return { sentence: 'De betaling is mislukt', canPay: true }
	}

	return { sentence: 'We kunnen de betaling nog niet tonen', canPay: false }
}
