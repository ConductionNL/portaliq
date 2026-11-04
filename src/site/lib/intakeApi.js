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
 * Every call takes the connection it uses as its first argument:
 * `{base, portal, token, fetchImpl}`, where `portal` and `token` may be left
 * out. The blocks pass no `fetchImpl` and get `window.fetch`; the tests pass
 * a recorder.
 */

/**
 * @typedef {object} IntakeConnection
 * @property {string} base The portal API base, e.g. `/apps/portaliq/portal/api`.
 * @property {string} [portal] The portal slug, or ''.
 * @property {string} [token] The portal bearer, or ''.
 * @property {((url: string, init?: object) => Promise<object>)|null} [fetchImpl] The fetch to use.
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
 * POST a JSON body to one intake endpoint, with the bearer when there is one.
 *
 * @param {IntakeConnection} conn The connection.
 * @param {{path: string, body: object}} request The endpoint path and the body.
 * @return {Promise<object>} The response.
 */
function postIntake(conn, request) {
	return fetcher(conn.fetchImpl)(intakeUrl(conn.base, request.path), {
		method: 'POST',
		headers: headersFor(conn.token, true),
		body: JSON.stringify(request.body),
	})
}

/**
 * A body with the portal slug added last, when there is one.
 *
 * @param {object} body The body.
 * @param {string} [portal] The portal slug, or ''.
 * @return {object} The body, changed in place.
 */
function withPortal(body, portal) {
	if (portal) {
		body.portal = portal
	}
	return body
}

/**
 * An error for an intake answer that is no success, carrying its status.
 *
 * @param {string} what The call, e.g. `intake submit`.
 * @param {object} response The response.
 * @return {Error} The error.
 */
function intakeError(what, response) {
	const error = new Error(`${what} ${response.status}`)
	error.status = response.status
	return error
}

/**
 * The published catalogue, by topic, as it stands right now.
 *
 * Read on every call and never kept: an entry withdrawn from the catalogue is
 * gone the next time the page is opened. An entry without a route cannot start
 * a form, so it is not offered, and a topic left with no entries is dropped.
 *
 * @param {IntakeConnection} conn The connection (`base`, `portal`, `fetchImpl`).
 * @return {Promise<Array<{topic: string, entries: Array<object>}>>} The topics.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-citizens-entry-point-is-composed-content-listing-the-published-catalogue-req-pifo-006
 */
export async function fetchCatalogue(conn) {
	const response = await fetcher(conn.fetchImpl)(
		intakeUrl(conn.base, '/intake/catalogue', { portal: conn.portal }),
		{
			headers: headersFor(''),
		},
	)
	if (!response.ok) {
		throw intakeError('intake catalogue', response)
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
 * @param {IntakeConnection} conn The connection.
 * @param {string} route The binding route.
 * @return {Promise<{state: string, render: object}>} The view.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-applicant-block-is-prefilled-from-the-signed-in-identity-only-req-pifo-003
 */
export async function loadForm(conn, route) {
	const response = await fetcher(conn.fetchImpl)(
		intakeUrl(conn.base, '/intake/form', { route, portal: conn.portal }),
		{
			headers: headersFor(conn.token),
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
		} else if (field.type === 'familyMembers' || field.type === 'group') {
			values[name] = []
		} else if (field.type === 'addressNL') {
			values[name] = {
				postcode: '',
				number: '',
				letter: '',
				addition: '',
				street: '',
				town: '',
			}
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
 * @param {IntakeConnection} conn The connection.
 * @param {object} submission What is sent.
 * @param {string} submission.route The binding route.
 * @param {object} submission.answers The answers, keyed by field name.
 * @param {string[]} [submission.statements] The keys of the statements ticked.
 * @param {Record<string, string>} [submission.verifiedEmails] The proof of each verified e-mail address, by address.
 * @return {Promise<{reference: string, confirmationText: string, errors: object, moments?: object}>} The outcome.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
 * @spec openspec/changes/submit-creates-the-case-directly/specs/portal-intake-form/spec.md#requirement-the-case-is-created-in-the-request-and-the-citizen-gets-its-reference-at-once-req-pifo-005
 */
export async function submitIntake(conn, submission) {
	const response = await postIntake(conn, {
		path: '/intake/submit',
		body: submissionBody(submission, conn.portal),
	})
	const parsed = await response.json().catch(() => ({}))

	// 400: the portal's own checks. 422: the destination refused an answer
	// (decision 179); nothing was stored and the answers stay in the form.
	if (response.status === 400 || response.status === 422) {
		return { reference: '', confirmationText: '', errors: parsed?.errors || {} }
	}

	if (!response.ok || !parsed?.reference) {
		throw intakeError('intake submit', response)
	}

	return submittedOutcome(parsed)
}

/**
 * The body of a submission: the route and answers, and the verified e-mail
 * addresses and ticked statements when there are any.
 *
 * @param {{route: string, answers: object, statements?: string[], verifiedEmails?: Record<string, string>}} submission What is sent.
 * @param {string} [portal] The portal slug, or ''.
 * @return {object} The body.
 */
function submissionBody(
	{ route, answers, statements = [], verifiedEmails = {} },
	portal,
) {
	const body = { route, answers: answers || {} }
	if (verifiedEmails && Object.keys(verifiedEmails).length > 0) {
		body.verifiedEmails = verifiedEmails
	}
	withPortal(body, portal)
	if (Array.isArray(statements) && statements.length > 0) {
		body.statements = statements
	}
	return body
}

/**
 * The outcome of an accepted submission.
 *
 * @param {object} parsed The answer's body.
 * @return {{reference: string, confirmationText: string, confirmation: object|null, mailedTo: string, moments: object, errors: object}} The outcome.
 */
function submittedOutcome(parsed) {
	return {
		reference: String(parsed.reference),
		confirmationText: String(parsed.confirmationText || ''),
		confirmation: parsed.confirmation || null,
		mailedTo: String(parsed.mailedTo || ''),
		moments: {
			receivedAt: String(parsed.receivedAt || ''),
			termStartsAt: String(parsed.termStartsAt || ''),
			deadline: String(parsed.deadline || ''),
		},
		errors: {},
	}
}

/**
 * The state behind a reference, or null when there is none.
 *
 * @param {IntakeConnection} conn The connection (`base`, `portal`, `fetchImpl`).
 * @param {string} reference The reference the visitor was given.
 * @return {Promise<object|null>} The status, or null.
 *
 * @spec openspec/changes/portal-intake-form-as-an-object/specs/portal-intake-form/spec.md#requirement-the-case-is-created-asynchronously-and-the-citizen-gets-a-reference-at-once-req-pifo-005
 */
export async function lookUpStatus(conn, reference) {
	const trimmed = String(reference || '').trim()
	if (trimmed === '') {
		return null
	}

	const response = await fetcher(conn.fetchImpl)(
		intakeUrl(conn.base, '/intake/status', {
			reference: trimmed,
			portal: conn.portal,
		}),
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
 * A due date as a Dutch reader writes it, or '' when it is not a date.
 *
 * @param {string|undefined} value An ISO 8601 moment.
 * @return {string} Such as "2 november 2026".
 *
 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
 */
function readableDate(value) {
	const moment = new Date(String(value || ''))
	if (!value || Number.isNaN(moment.getTime())) {
		return ''
	}

	return new Intl.DateTimeFormat('nl-NL', {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
		timeZone: 'Europe/Amsterdam',
	}).format(moment)
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
 * @spec openspec/changes/woo-request-intake-through-opencatalogi/specs/portal-intake-form/spec.md#requirement-a-woo-request-form-is-delivered-to-opencatalogis-intake
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

	const dueDate = status.state === 'registered' ? readableDate(status.dueAt) : ''
	if (dueDate && status.externalReference) {
		// A Woo request whose statutory term runs. The date is the one the
		// receiving app armed; without one this sentence is never shown.
		return {
			tone: 'success',
			sentence: `Uw verzoek ${reference} is ontvangen onder kenmerk ${status.externalReference}. U krijgt uiterlijk ${dueDate} een besluit.`,
		}
	}

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
 * Street and town for a postcode and house number, from the portal's address
 * route. A miss, a refusal and a network failure all answer null, so the form
 * falls back to typing (data-lookups-and-checks-in-forms REQ-DIF-001).
 *
 * @param {IntakeConnection} conn The connection (`base`, `fetchImpl`).
 * @param {object} block The address block (`postcode`, `number`, `letter`, `addition`).
 * @return {Promise<{street: string, town: string}|null>} The address, or null.
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */
export async function lookupAddress(conn, block) {
	try {
		const response = await fetcher(conn.fetchImpl)(
			intakeUrl(conn.base, '/intake/address', {
				postcode: block.postcode,
				number: block.number,
				letter: block.letter,
				addition: block.addition,
			}),
			{ headers: headersFor('') },
		)
		if (!response.ok) {
			return null
		}
		const body = await response.json()
		return body && body.street && body.town
			? { street: String(body.street), town: String(body.town) }
			: null
	} catch {
		return null
	}
}

/**
 * The resident's partner and children from the BRP, with the bearer. `null`
 * when nothing can be offered (no DigiD session, or the register is down), so
 * the form says so and does not pretend there is nobody.
 *
 * @param {IntakeConnection} conn The connection (`base`, `token`, `fetchImpl`).
 * @param {boolean} sameAddressOnly Keep only people at the resident's address.
 * @return {Promise<Array<{ref: string, name: string, relation: string, birthYear: string}>|null>} The people, or null.
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */
export async function fetchFamily(conn, sameAddressOnly) {
	try {
		const response = await fetcher(conn.fetchImpl)(
			intakeUrl(conn.base, '/intake/family', {
				sameAddressOnly: sameAddressOnly ? '1' : '0',
			}),
			{ headers: headersFor(conn.token) },
		)
		if (!response.ok) {
			return null
		}
		const body = await response.json()
		return Array.isArray(body?.members) ? body.members : null
	} catch {
		return null
	}
}

/**
 * Ask the server to decide a step: the rule engine runs there, and the answer
 * is the outcome, the field it fills and the step it opens. A 503 is the engine
 * being down, which the form shows with "Opnieuw proberen" and keeps its answers.
 *
 * @param {IntakeConnection} conn The connection.
 * @param {{route: string, step: string, answers: object}} ask The binding route of the form, the id of the step that decides and the answers so far.
 * @return {Promise<{outcome: string, output: string, nextStep: string}>} The decision.
 * @spec openspec/changes/form-flow-repeating-groups-calculations-and-decisions/tasks.md#t06
 */
export async function decideStep(conn, { route, step, answers }) {
	const response = await postIntake(conn, {
		path: '/intake/decide',
		body: withPortal({ route, step, answers: answers || {} }, conn.portal),
	})
	const parsed = await response.json().catch(() => ({}))
	if (!response.ok) {
		throw intakeError('intake decide', response)
	}

	return {
		outcome: String(parsed.outcome || ''),
		output: String(parsed.output || ''),
		nextStep: String(parsed.nextStep || ''),
	}
}

/**
 * Ask the portal for the checkout of a submitted request's fee. The amount is
 * the case type's: nothing but the reference is sent.
 *
 * @param {IntakeConnection} conn The connection.
 * @param {string} reference The submission's reference.
 * @return {Promise<{ok: boolean, checkoutUrl: string, status: number}>} The checkout, or why not.
 * @spec openspec/changes/intake-pay-on-submit/tasks.md#t06
 */
export async function payIntake(conn, reference) {
	try {
		const response = await postIntake(conn, {
			path: '/intake/pay',
			body: withPortal({ reference }, conn.portal),
		})
		const body = await response.json().catch(() => ({}))
		const url = typeof body?.checkoutUrl === 'string' ? body.checkoutUrl : ''
		return {
			ok: response.ok && url !== '',
			checkoutUrl: url,
			status: response.status,
		}
	} catch {
		return { ok: false, checkoutUrl: '', status: 0 }
	}
}

/**
 * Ask for a code to be sent to an address the form wants verified
 * (resident-identity-in-forms). A refusal is an answer: `error` names why.
 *
 * @param {IntakeConnection} conn The connection.
 * @param {{route: string, email: string}} ask The binding route of the form and the address.
 * @return {Promise<{ok: boolean, error: string, resendAfter: number}>} The outcome.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
export async function requestEmailCode(conn, { route, email }) {
	try {
		const response = await postIntake(conn, {
			path: '/intake/email-code',
			body: withPortal({ route, email }, conn.portal),
		})
		const parsed = await response.json().catch(() => ({}))
		return {
			ok: response.ok === true,
			error: response.ok ? '' : String(parsed?.error || 'failed'),
			resendAfter: Number(parsed?.resendAfter) || 60,
		}
	} catch {
		return { ok: false, error: 'failed', resendAfter: 60 }
	}
}

/**
 * Check the code the resident typed. A right code answers the proof the form
 * sends along with its answers.
 *
 * @param {IntakeConnection} conn The connection.
 * @param {{route: string, email: string, code: string}} ask The binding route of the form, the address and the code.
 * @return {Promise<{ok: boolean, error: string, proof: string}>} The outcome.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
export async function checkEmailCode(conn, { route, email, code }) {
	try {
		const response = await postIntake(conn, {
			path: '/intake/email-code/check',
			body: withPortal({ route, email, code }, conn.portal),
		})
		const parsed = await response.json().catch(() => ({}))
		if (
			response.ok
			&& typeof parsed?.proof === 'string'
			&& parsed.proof !== ''
		) {
			return { ok: true, error: '', proof: parsed.proof }
		}
		return { ok: false, error: String(parsed?.error || 'failed'), proof: '' }
	} catch {
		return { ok: false, error: 'failed', proof: '' }
	}
}
