// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// When a step happens, as a highlight card says it (steps-highlight): the
// weekday and the day, and the time when the date carries one. Dutch writes
// "dinsdag 13 oktober, 10.00 uur", English "Tuesday 13 October, 10:00".
// Imports nothing, so node tests it.
//
// @spec openspec/changes/steps-highlight/specs/site-mijn-omgeving/spec.md#requirement-the-steps-may-draw-the-step-that-matters-now-as-a-highlight

/**
 * A step's moment in words, or '' without a readable date.
 *
 * @param {string|undefined} value A date (`2026-10-13`) or a date-time.
 * @param {string} [locale] `nl` or `en`.
 * @return {string} The words.
 */
export function stepMoment(value, locale = 'nl') {
	const text = String(value ?? '').trim()
	const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text)
	const date = day
		? new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))
		: new Date(text)
	if (text === '' || Number.isNaN(date.getTime())) {
		return ''
	}
	const english = String(locale).toLowerCase().startsWith('en')
	const words = new Intl.DateTimeFormat(english ? 'en-GB' : 'nl-NL', {
		weekday: 'long',
		day: 'numeric',
		month: 'long',
	}).format(date)
	const timed = !day && (date.getHours() !== 0 || date.getMinutes() !== 0)
	if (!timed) {
		return words
	}
	const hh = String(date.getHours()).padStart(2, '0')
	const mm = String(date.getMinutes()).padStart(2, '0')
	return english ? `${words}, ${hh}:${mm}` : `${words}, ${hh}.${mm} uur`
}
