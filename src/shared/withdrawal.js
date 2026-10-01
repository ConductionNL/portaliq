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
 * The case's answers, without the envelope, the files and the withdrawal fields.
 *
 * @param {object} caseRow The case.
 * @return {string[]}
 */
export function caseFieldNames(caseRow) {
	return Object.keys(caseRow || {}).filter(
		(key) =>
			key !== '@self' && key !== '_files' && !WITHDRAWAL_FIELDS.includes(key),
	)
}
