// SPDX-License-Identifier: EUPL-1.2
//
// What the case screen shows about withdrawing a request
// (case-actions-withdraw-screen). The screen never decides whether a request
// can be withdrawn: it renders the `withdrawal` answer the server returns
// with the case (CitizenWritableSetResolver::withdrawal()).
//
// @spec openspec/specs/citizen-case-withdraw-screen/spec.md#requirement-the-case-screen-offers-withdrawal-exactly-as-the-server-declares-it-req-wds-001

/**
 * The fields a withdrawal writes, shown as the withdrawn state and never as
 * ordinary answers.
 *
 * @type {string[]}
 */
export const WITHDRAWAL_FIELDS = ['withdrawnAt', 'withdrawalReason']

/**
 * What to show: nothing, the button, the closed reason, or the withdrawn state.
 *
 * @param {object|undefined} withdrawal The server's `{declared, open, reason, confirmText}`.
 * @param {object} caseRow The case.
 * @return {object} `{kind: 'none'}`, `{kind: 'button', confirmText}`, `{kind: 'closed', reason}` or `{kind: 'withdrawn', withdrawnAt, reason}`.
 */
export function withdrawalView(withdrawal, caseRow) {
	if (typeof caseRow?.withdrawnAt === 'string' && caseRow.withdrawnAt !== '') {
		return {
			kind: 'withdrawn',
			withdrawnAt: caseRow.withdrawnAt,
			reason:
				typeof caseRow.withdrawalReason === 'string'
					? caseRow.withdrawalReason
					: '',
		}
	}
	if (!withdrawal || withdrawal.declared !== true) {
		return { kind: 'none' }
	}
	if (withdrawal.open === true) {
		return {
			kind: 'button',
			confirmText:
				typeof withdrawal.confirmText === 'string'
					? withdrawal.confirmText
					: '',
		}
	}
	return {
		kind: 'closed',
		reason: typeof withdrawal.reason === 'string' ? withdrawal.reason : '',
	}
}

/**
 * The answers the case screen lists: the fields the writable set names, in
 * its order, and nothing else (citizen-case-shows-only-its-fields).
 *
 * The case row also carries what the collection shows elsewhere on the page
 * (a number, a status, a date). Those are not answers the resident gave, so
 * they are never listed here as one, with a sentence saying they cannot be
 * changed. The withdrawal fields are the withdrawn state, never an answer.
 *
 * @param {object} caseRow The case.
 * @param {object} writableSet The server's writable set (`fields` keyed by name).
 * @return {string[]}
 */
export function caseFieldNames(caseRow, writableSet) {
	const named = writableSet?.fields
	if (
		!caseRow
		|| typeof caseRow !== 'object'
		|| !named
		|| typeof named !== 'object'
		|| Array.isArray(named)
	) {
		return []
	}
	return Object.keys(named).filter(
		(key) =>
			key !== '@self' && key !== '_files' && !WITHDRAWAL_FIELDS.includes(key),
	)
}
