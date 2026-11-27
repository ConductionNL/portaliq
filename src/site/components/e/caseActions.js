// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The create actions a contribution places on a case page
// (case-page-objection-and-complaint): what the case screen draws from the
// server's `caseActions`, the `onCase` value a submit carries, and the receipt.
// The server decides open or closed; nothing here reckons a date.

/**
 * The case actions to draw, as the server listed them: an open one as a
 * button with its form, a closed one as its sentence.
 *
 * @param {object|null} data The citizen case answer.
 * @return {Array<{key: string, label: string, open: boolean, closedText: string, form: object|null}>}
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-case-page-offers-the-actions-the-server-lists-req-coc-002
 */
export function caseActionsView(data) {
	const listed = Array.isArray(data?.caseActions) ? data.caseActions : []
	return listed
		.filter(
			(entry) =>
				entry && typeof entry.action === 'string' && entry.action !== '',
		)
		.map((entry) => {
			const open =
				entry.open === true && entry.form && typeof entry.form === 'object'
			return {
				key: `${entry.app || ''}:${entry.action}`,
				label: entry.label || entry.action,
				open: Boolean(open),
				closedText: open ? '' : entry.closedText || '',
				form: open ? entry.form : null,
			}
		})
}

/**
 * The `onCase` value of a submit from this case: `<register>/<schema>/<id>`.
 *
 * @param {object} collection The case's collection (`{register, schema}`).
 * @param {string} id The case id.
 * @return {string} The value, empty when a part is missing.
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-server-fills-in-the-case-and-the-guard-still-runs-req-coc-004
 */
export function onCaseTarget(collection, id) {
	const parts = [collection?.register, collection?.schema, id]
	if (parts.some((part) => typeof part !== 'string' || part === '')) {
		return ''
	}
	return parts.join('/')
}

/**
 * The receipt after a create filed from the case page: the reference when
 * the server gave one, and where the new case is found.
 *
 * @param {Function} t The translator.
 * @param {object|null} object The created object.
 * @return {Array<string>} The lines.
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-resident-gets-a-receipt-on-the-case-page-req-coc-005
 */
export function receiptLines(t, object) {
	const reference =
		object?.reference || object?.identifier || object?.receipt?.reference || ''
	const first =
		reference !== ''
			? t('We have received it. Reference {reference}.', { reference })
			: t('We have received it.')
	return [first, t('You will find it as a case of its own in My cases.')]
}

/**
 * What a refused submit says: the server's sentence on a closed window,
 * else nothing (the form shows its own words).
 *
 * @param {object} result The create's result.
 * @return {string} The sentence.
 *
 * @spec openspec/changes/archive/2026-11-24-case-page-objection-and-complaint/specs/citizen-case-objection-and-complaint/spec.md#requirement-the-window-is-computed-on-the-server-req-coc-003
 */
export function closedRefusal(result) {
	if (result && result.status === 409 && result.error === 'case_action_closed') {
		return result.message || ''
	}
	return ''
}
