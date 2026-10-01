// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The asker's side of an access request on the site (identity-access-requests,
// ported from src/portal/components/AccessRequestsPage.jsx). Imports nothing,
// so the node specs run it as a plain script.

/**
 * What is missing before a request can be sent, as an English source key, or
 * '' when nothing is.
 *
 * @param {{onBehalfOf: string, reason: string}} draft The form values.
 * @return {string} The problem, or ''.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
 */
export function requestProblem(draft) {
	if (String(draft?.onBehalfOf || '').trim() === '') {
		return 'Say whose cases you need access to.'
	}
	if (String(draft?.reason || '').trim() === '') {
		return 'Give a reason for your request.'
	}
	return ''
}

/**
 * The requests, newest first; a request without a date goes last.
 *
 * @param {Array<object>} requests The requests.
 * @return {Array<object>} A sorted copy.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
 */
export function newestFirst(requests) {
	const stamp = (request) => {
		const time = Date.parse(request?.requestedAt || '')
		return Number.isNaN(time) ? -Infinity : time
	}
	return [...(Array.isArray(requests) ? requests : [])].sort((a, b) => stamp(b) - stamp(a))
}

/**
 * The words a request's state reads as, as an English source key. Anything
 * the organisation has not answered yet reads as waiting.
 *
 * @param {string} state The stored state.
 * @return {string} The label key.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
 */
export function stateLabel(state) {
	if (state === 'granted') {
		return 'Granted'
	}
	if (state === 'refused') {
		return 'Refused'
	}
	return 'Waiting for an answer'
}

/**
 * The sentence for a failed send, as an English source key.
 *
 * @param {object} outcome The api answer `{ok, error}`.
 * @return {string} The sentence key.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-ask-for-access-to-cases-req-srp-039
 */
export function sendProblem(outcome) {
	return outcome?.error === 'reason_required'
		? 'Give a reason for your request.'
		: 'Your request could not be sent. Try again later.'
}
