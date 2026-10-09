// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * The decisions behind a form that asks for one entry of a day
 * (action-form-day-entry), without Vue, so `node --test` asserts them on
 * their own (tests/action-form-day-entry.spec.mjs): the hours worked out from
 * two times and a pause, and the values of check cards.
 *
 * The server works the hours out again from the same times
 * (`DurationField::stamp`), so the number shown here is only a preview.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */

const TIME = /^([01]?\d|2[0-3])[:.]([0-5]\d)$/

/**
 * Minutes since midnight of an `HH:MM` or `HH.MM` time, or null.
 *
 * @param {*} time The time.
 * @return {number|null} The minutes.
 */
function minutesOf(time) {
	const match = TIME.exec(String(time ?? '').trim())
	if (!match) {
		return null
	}
	return Number(match[1]) * 60 + Number(match[2])
}

/**
 * The hours between two times minus a pause in minutes, rounded to two
 * decimals; null when a time is missing or the result is not more than zero.
 *
 * @param {*} from The start time.
 * @param {*} to The end time.
 * @param {*} pause The pause in minutes ('' or absent is none).
 * @return {number|null} The hours.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export function durationHours(from, to, pause) {
	const start = minutesOf(from)
	const end = minutesOf(to)
	const minus = pause === undefined || pause === null || pause === '' ? 0 : Number(pause)
	if (start === null || end === null || !Number.isFinite(minus)) {
		return null
	}
	const worked = end - start - minus
	if (worked <= 0) {
		return null
	}
	return Math.round((worked / 60) * 100) / 100
}

/**
 * The fields of an action whose value the form works out, with what each reads.
 *
 * @param {object} action The action.
 * @return {Array<[string, {from: string, to: string, minus?: string}]>} The pairs.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export function computedFields(action) {
	const configs = (action && action.fieldConfigs) || {}
	return Object.entries(configs)
		.filter(([, config]) => config && config.computed && config.computed.kind === 'duration')
		.map(([field, config]) => [field, config.computed])
}

/**
 * The values with every computed field worked out from the others: the
 * hours as text, or '' while a time is missing. Returns the same object when
 * nothing changed, so a watcher does not run again.
 *
 * @param {object} action The action.
 * @param {Record<string, *>} values The current values.
 * @return {Record<string, *>} The values.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export function withComputed(action, values) {
	let out = values
	for (const [field, computed] of computedFields(action)) {
		const hours = durationHours(
			values[computed.from],
			values[computed.to],
			computed.minus ? values[computed.minus] : 0,
		)
		const text = hours === null ? '' : String(hours)
		if (values[field] !== text) {
			out = out === values ? { ...values } : out
			out[field] = text
		}
	}
	return out
}

/**
 * The chosen values of check cards as strings, never null.
 *
 * @param {*} value The field's value: an array, or '' before anything is chosen.
 * @return {string[]} The chosen values.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export function checkedValues(value) {
	return Array.isArray(value) ? value.map((entry) => String(entry)) : []
}

/**
 * The chosen values after one card was ticked or unticked, in the order of
 * the options.
 *
 * @param {*} value The field's value.
 * @param {Array<{value: *}>} options The options.
 * @param {string} toggled The value of the card.
 * @return {string[]} The new values.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export function toggledValues(value, options, toggled) {
	const chosen = new Set(checkedValues(value))
	if (chosen.has(String(toggled))) {
		chosen.delete(String(toggled))
	} else {
		chosen.add(String(toggled))
	}
	return (options || [])
		.map((option) => String(option.value))
		.filter((option) => chosen.has(option))
}

/**
 * Hours as the form says them: "8", "7,5" in Dutch, "7.5" in English.
 *
 * @param {string|number} hours The hours.
 * @param {string} locale The page's language.
 * @return {string} The number as text.
 *
 * @spec openspec/changes/action-form-day-entry/specs/site-forms/spec.md
 */
export function hoursText(hours, locale) {
	const number = Number(hours)
	if (String(hours) === '' || !Number.isFinite(number)) {
		return ''
	}
	return new Intl.NumberFormat(locale || 'nl', { maximumFractionDigits: 2 }).format(number)
}
