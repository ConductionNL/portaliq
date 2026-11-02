// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The rules of the Machtigingen sections (site-mandates-the-represented-manage
// T4, boards Machtigingen and Machtigen): the end date typed as day, month and
// year, the words for a refusal, what a mandate covers, its end and its state.
// Imports nothing, so tests/mandates-page.spec.mjs runs it as plain node.
//
// @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4

/**
 * The end day typed as day, month and year, as `YYYY-MM-DD`.
 *
 * @param {{day: string, month: string, year: string}} end The three fields.
 * @return {string|null} '' when all three are empty, null when the date is
 *   incomplete or does not exist. Whether it lies in the future the server says.
 */
export function endDayOf(end) {
	const parts = [end?.day, end?.month, end?.year].map((part) =>
		String(part ?? '').trim(),
	)
	if (parts.every((part) => part === '')) {
		return ''
	}
	const [day, month, year] = parts.map(Number)
	if (
		!/^\d{1,2}$/.test(parts[0])
		|| !/^\d{1,2}$/.test(parts[1])
		|| !/^\d{4}$/.test(parts[2])
	) {
		return null
	}
	const date = new Date(Date.UTC(year, month - 1, day))
	if (
		date.getUTCFullYear() !== year
		|| date.getUTCMonth() !== month - 1
		|| date.getUTCDate() !== day
	) {
		return null
	}
	return date.toISOString().slice(0, 10)
}

/**
 * The sentence for a refusal of the invitation dialog.
 *
 * @param {string} code The server's or the form's code.
 * @return {string} The English source string.
 */
export function mandateProblemText(code) {
	const texts = {
		bad_email: 'Fill in a valid e-mail address.',
		bad_end_date: 'Fill in an end date after today, or leave it empty.',
		no_case_type: 'Choose at least one kind of case.',
		not_sent: 'The invitation could not be sent. Try again later.',
	}
	return texts[code] || 'That did not work. Try again later.'
}

/**
 * What a mandate covers: "All cases", or the titles of its kinds of case.
 *
 * @param {Array<string>} caseTypes The case type ids; empty for all.
 * @param {Array<{id: string, title: string}>} titles The known titles.
 * @param {(key: string) => string} t The translator.
 * @return {string}
 */
export function scopeText(caseTypes, titles, t) {
	const ids = Array.isArray(caseTypes) ? caseTypes : []
	if (ids.length === 0) {
		return t('All cases')
	}
	const byId = new Map((titles || []).map((type) => [type.id, type.title]))
	return ids.map((id) => byId.get(id) || id).join(', ')
}

/**
 * The end of a mandate in the reader's language, or "No end date".
 *
 * @param {string} expiresAt The end, or ''.
 * @param {(value: string) => string} format Formats a date.
 * @param {(key: string) => string} t The translator.
 * @return {string}
 */
export function endText(expiresAt, format, t) {
	return expiresAt ? format(expiresAt) : t('No end date')
}

/**
 * The state badge of an item in the given list.
 *
 * @param {{state: string}} item The mandate or invitation.
 * @return {string} The English source string.
 */
export function stateText(item) {
	return (
		{
			active: 'Active',
			pending: 'Waiting for an answer',
			expired: 'Expired',
		}[item?.state] || 'Active'
	)
}

/**
 * The end action of an item in the given list.
 *
 * @param {{kind: string}} item The mandate or invitation.
 * @return {string} The English source string.
 */
export function endActionText(item) {
	return item?.kind === 'invitation' ? 'Withdraw invitation' : 'Revoke'
}

/**
 * Who an item names: the account's name, else the address, else the holder.
 *
 * @param {object} item The mandate or invitation.
 * @return {{name: string, email: string}}
 */
export function whoOf(item) {
	const name = String(item?.name || '').trim()
	const email = String(item?.email || '').trim()
	if (name !== '') {
		return { name, email }
	}
	if (email !== '') {
		return { name: email, email: '' }
	}
	return { name: String(item?.holder || ''), email: '' }
}
