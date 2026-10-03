// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind the site's shared form field layer, without Vue, so
 * `node --test` asserts them on their own (tests/site-form-fields.spec.mjs).
 *
 * A date answer travels as one string per field: '' when nothing is typed,
 * `yyyy-mm-dd` when the three parts make a real date, and the typed parts as
 * `d-m-y` otherwise. The last shape never reaches a server: every renderer
 * checks it with `dateProblem()` before it sends.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 */

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

/**
 * Whether three numbers make a real calendar date.
 *
 * @param {number} day The day.
 * @param {number} month The month, 1 to 12.
 * @param {number} year The year, four digits.
 * @return {boolean} True for a date that exists.
 */
function realDate(day, month, year) {
	if (year < 1000 || year > 9999 || month < 1 || month > 12 || day < 1) {
		return false
	}
	const date = new Date(Date.UTC(year, month - 1, day))
	return (
		date.getUTCFullYear() === year
		&& date.getUTCMonth() === month - 1
		&& date.getUTCDate() === day
	)
}

/**
 * The value one date group sends for its three typed parts.
 *
 * @param {string} day What is in the Dag box.
 * @param {string} month What is in the Maand box.
 * @param {string} year What is in the Jaar box.
 * @return {string} '' when all three are empty, `yyyy-mm-dd` for a real date, else `d-m-y` as typed.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 */
export function dateValue(day, month, year) {
	const parts = [day, month, year].map((part) => String(part ?? '').trim())
	if (parts.every((part) => part === '')) {
		return ''
	}
	const [d, m, y] = parts
	if ([d, m, y].every((part) => /^\d+$/.test(part)) && y.length === 4) {
		const [dn, mn, yn] = [Number(d), Number(m), Number(y)]
		if (realDate(dn, mn, yn)) {
			return `${y}-${String(mn).padStart(2, '0')}-${String(dn).padStart(2, '0')}`
		}
	}
	return `${d}-${m}-${y}`
}

/**
 * The three parts a date group shows for a value: an ISO date split into day,
 * month and year without leading zeros, or the `d-m-y` it typed earlier.
 *
 * @param {string} value The field's value.
 * @return {{day: string, month: string, year: string}} The parts.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 */
export function dateParts(value) {
	const text = String(value ?? '').trim()
	const iso = ISO_DATE.exec(text.slice(0, 10))
	if (iso) {
		return {
			day: String(Number(iso[3])),
			month: String(Number(iso[2])),
			year: iso[1],
		}
	}
	const typed = text === '' ? [] : text.split('-')
	return {
		day: typed[0] || '',
		month: typed[1] || '',
		year: typed.slice(2).join('-'),
	}
}

/**
 * Whether a date field's value can be sent: empty, or a real `yyyy-mm-dd`.
 *
 * @param {string} value The field's value.
 * @return {boolean} True when the value is not a date the server can read.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-date-field-must-be-asked-as-day-month-and-year-req-smf-003
 */
export function dateProblem(value) {
	const text = String(value ?? '').trim()
	if (text === '') {
		return false
	}
	const iso = ISO_DATE.exec(text)
	return !(iso && realDate(Number(iso[3]), Number(iso[2]), Number(iso[1])))
}

/**
 * Whether a form needs the sentence that explains "(niet verplicht)": only
 * when it mixes required and optional fields.
 *
 * @param {boolean[]} requiredFlags One flag per visible field.
 * @return {boolean} True when some fields are required and some are not.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
 */
export function explainsOptional(requiredFlags) {
	const flags = Array.isArray(requiredFlags) ? requiredFlags : []
	return flags.some((flag) => flag === true) && flags.some((flag) => flag !== true)
}

/**
 * The entries of an error summary: one per field with a message, in the
 * order the fields stand on the form. A message for a field the form does
 * not show is listed last, without a target, so it is never lost.
 *
 * @param {string[]} order The field names in form order.
 * @param {Record<string, string>} errors The message per field.
 * @param {(field: string) => string} targetOf The element id a link focuses.
 * @return {Array<{field: string, target: string, message: string}>} The entries.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
 */
export function summaryEntries(order, errors, targetOf) {
	const messages = errors || {}
	const known = (Array.isArray(order) ? order : []).filter(
		(field) => typeof messages[field] === 'string' && messages[field] !== '',
	)
	const loose = Object.keys(messages).filter(
		(field) =>
			!known.includes(field)
			&& typeof messages[field] === 'string'
			&& messages[field] !== '',
	)
	return [
		...known.map((field) => ({
			field,
			target: targetOf(field),
			message: messages[field],
		})),
		...loose.map((field) => ({ field, target: '', message: messages[field] })),
	]
}
