// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// An employee fills a form in for a resident or a company with their work
// account (resident-identity-in-forms REQ-RIF-005, board FormulierInloggen).
// The calls ride on the employee's Nextcloud session, so the writes carry
// Nextcloud's request token. The chosen applicant waits in sessionStorage
// while the employee signs in, and is taken once on the way back.

import { intakeUrl, submitOutcome } from './intakeApi.js'

const CHOICE_KEY = 'portaliq.staff.applicant'

/**
 * The fetch to use.
 *
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl A fetch, or null for the window's.
 * @return {(url: string, init?: object) => Promise<object>} The fetch.
 */
function fetcher(fetchImpl) {
	return fetchImpl || ((...args) => window.fetch(...args))
}

/**
 * Headers for a write on the employee's session.
 *
 * @param {string} requestToken Nextcloud's request token.
 * @return {object} The headers.
 */
function writeHeaders(requestToken) {
	return {
		Accept: 'application/json',
		'Content-Type': 'application/json',
		requesttoken: String(requestToken || ''),
	}
}

/**
 * Where an employee signs in with their work account and comes back.
 *
 * @param {string} root The instance root, e.g. from `instanceRootFrom()`.
 * @param {string} returnTo The path and query to come back to.
 * @return {string} The address of Nextcloud's own sign-in form.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export function workAccountLoginUrl(root, returnTo) {
	const back = String(returnTo || '/')
	const safe = back.startsWith('/') && !back.startsWith('//') ? back : '/'
	return `${String(root || '').replace(/\/$/, '')}/login?redirect_url=${encodeURIComponent(safe)}`
}

/**
 * Keep the applicant the employee chose while they sign in.
 *
 * @param {string} route The form's binding route.
 * @param {{kind: string, value: string}} applicant The applicant.
 * @param {Storage|null} storage The storage; sessionStorage when null.
 * @return {void}
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export function rememberStaffChoice(route, applicant, storage = null) {
	try {
		;(storage || window.sessionStorage).setItem(
			CHOICE_KEY,
			JSON.stringify({ route, kind: applicant.kind, value: applicant.value }),
		)
	} catch {
		// Without storage the employee chooses again after signing in.
	}
}

/**
 * The applicant chosen for this form before signing in, taken once, or null.
 *
 * @param {string} route The form's binding route.
 * @param {Storage|null} storage The storage; sessionStorage when null.
 * @return {{kind: string, value: string}|null} The applicant.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export function takeStaffChoice(route, storage = null) {
	try {
		const store = storage || window.sessionStorage
		const saved = JSON.parse(store.getItem(CHOICE_KEY) || 'null')
		store.removeItem(CHOICE_KEY)
		if (saved && saved.route === route && saved.kind && saved.value) {
			return { kind: String(saved.kind), value: String(saved.value) }
		}
	} catch {
		// Nothing usable was kept.
	}

	return null
}

/**
 * Nextcloud's request token for this browser session, or ''.
 *
 * @param {string} root The instance root.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<string>} The token.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export async function staffRequestToken(root, fetchImpl = null) {
	const response = await fetcher(fetchImpl)(
		`${String(root || '').replace(/\/$/, '')}/csrftoken`,
		{ headers: { Accept: 'application/json' } },
	)
	if (!response.ok) {
		return ''
	}

	const body = await response.json().catch(() => ({}))
	return String(body?.token || '')
}

/**
 * Start the form for an applicant.
 *
 * States: `form` (the render, filled in for the applicant), `signIn` (no work
 * account session yet), `notAllowed` (not in the desk group, or the form does
 * not allow employees) and `invalid` (the number is not right).
 *
 * @param {string} base The portal API base.
 * @param {string} route The form's binding route.
 * @param {string} portal The portal slug, or ''.
 * @param {{kind: string, value: string}} applicant The applicant.
 * @param {string} requestToken Nextcloud's request token.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<{state: string, render: object}>} The view.
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export async function startAsStaff(
	base,
	route,
	portal,
	applicant,
	requestToken,
	fetchImpl = null,
) {
	const body = { route, applicant }
	if (portal) {
		body.portal = portal
	}

	const response = await fetcher(fetchImpl)(
		intakeUrl(base, '/intake/staff/start'),
		{
			method: 'POST',
			headers: writeHeaders(requestToken),
			body: JSON.stringify(body),
		},
	)
	const parsed = await response.json().catch(() => ({}))
	if (response.status === 401) {
		return { state: 'signIn', render: {} }
	}

	if (response.status === 400) {
		return { state: 'invalid', render: {} }
	}

	if (!response.ok) {
		return { state: 'notAllowed', render: {} }
	}

	return { state: 'form', render: parsed || {} }
}

/**
 * Send the form for an applicant.
 *
 * @param {string} base The portal API base.
 * @param {object} input `route`, `portal`, `applicant`, `answers`, `statements` and `verifiedEmails`.
 * @param {string} requestToken Nextcloud's request token.
 * @param {((url: string, init?: object) => Promise<object>)|null} fetchImpl The fetch to use.
 * @return {Promise<{reference: string, confirmationText: string, errors: object, moments?: object}>} The outcome, as submitIntake().
 *
 * @spec openspec/changes/resident-identity-in-forms/specs/portal-form-identity/spec.md#requirement-an-employee-can-fill-in-a-form-for-a-resident-or-company-req-rif-005
 */
export async function submitAsStaff(base, input, requestToken, fetchImpl = null) {
	const body = {
		route: input.route,
		applicant: input.applicant,
		answers: input.answers || {},
	}
	if (input.portal) {
		body.portal = input.portal
	}
	if (Array.isArray(input.statements) && input.statements.length > 0) {
		body.statements = input.statements
	}
	if (input.verifiedEmails && Object.keys(input.verifiedEmails).length > 0) {
		body.verifiedEmails = input.verifiedEmails
	}

	const response = await fetcher(fetchImpl)(
		intakeUrl(base, '/intake/staff/submit'),
		{
			method: 'POST',
			headers: writeHeaders(requestToken),
			body: JSON.stringify(body),
		},
	)

	return submitOutcome(response)
}
