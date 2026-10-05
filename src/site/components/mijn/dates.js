// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Dates as the school blocks print them (site-school-blocks): a day and a
// short month for a date tile, a long date for a news row, and whether a
// date has passed. A date-only value (`2026-10-07`) is a calendar day, not a
// moment, so it is read in local time and never shifts a day at midnight UTC.
//
// Imports nothing, so node tests it (tests/site-school-blocks.spec.mjs).
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label

/**
 * A stored value as a Date, or null when it is not a date.
 *
 * @param {string} value An ISO date (`YYYY-MM-DD`) or date-time.
 * @return {Date|null} The date.
 */
export function toDate(value) {
	const text = String(value ?? '').trim()
	const day = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text)
	const date = day
		? new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))
		: new Date(text)
	return text === '' || Number.isNaN(date.getTime()) ? null : date
}

/**
 * The BCP 47 language a date is printed in.
 *
 * @param {string} [locale] The page language.
 * @return {string} `nl-NL` or `en-GB`.
 */
export function dateLocale(locale) {
	const raw =
		locale
		|| (typeof document !== 'undefined' && document.documentElement?.lang)
		|| 'nl'
	return String(raw).toLowerCase().startsWith('en') ? 'en-GB' : 'nl-NL'
}

/**
 * A date tile's two lines: the day and the short month without a dot.
 *
 * @param {string} value The date.
 * @param {string} [locale] The page language.
 * @return {{day: string, month: string}|null} The lines, or null.
 */
export function dayAndMonth(value, locale) {
	const date = toDate(value)
	if (!date) {
		return null
	}
	const month = new Intl.DateTimeFormat(dateLocale(locale), { month: 'short' })
		.format(date)
		.replace(/\.$/, '')
	return { day: String(date.getDate()), month }
}

/**
 * A long date: "2 oktober 2026".
 *
 * @param {string} value The date.
 * @param {string} [locale] The page language.
 * @return {string} The date, or '' when it is not one.
 */
export function longDate(value, locale) {
	const date = toDate(value)
	return date
		? new Intl.DateTimeFormat(dateLocale(locale), {
				day: 'numeric',
				month: 'long',
				year: 'numeric',
			}).format(date)
		: ''
}

/**
 * A short label for one day or a run of days: "7 okt", "17 - 25 okt",
 * "30 sep - 2 okt".
 *
 * @param {string} start The first day.
 * @param {string} [end] The last day.
 * @param {string} [locale] The page language.
 * @return {string} The label, or '' when the start is not a date.
 */
export function dayLabel(start, end, locale) {
	const first = dayAndMonth(start, locale)
	if (!first) {
		return ''
	}
	const last = end ? dayAndMonth(end, locale) : null
	if (!last || (last.day === first.day && last.month === first.month)) {
		return `${first.day} ${first.month}`
	}
	return last.month === first.month
		? `${first.day} - ${last.day} ${last.month}`
		: `${first.day} ${first.month} - ${last.day} ${last.month}`
}

/**
 * Whether a day lies before today (the end day, for a run of days).
 *
 * @param {string} start The first day.
 * @param {string} [end] The last day.
 * @param {Date} [now] Today, for a test.
 * @return {boolean} True when the whole run is over.
 */
export function isPast(start, end, now = new Date()) {
	const last = toDate(end) || toDate(start)
	if (!last) {
		return false
	}
	const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
	const day = new Date(last.getFullYear(), last.getMonth(), last.getDate())
	return day < today
}
