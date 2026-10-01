// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Dates on the slice-e screens, in the reader's language. Imports nothing, so
// the node specs run it as a plain script.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040

/**
 * A moment as a long date in the reader's language, or '' when it does not parse.
 *
 * @param {string} value An ISO date or date-time.
 * @param {string} locale The reader's locale.
 * @return {string} The date.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
 */
export function longDate(value, locale) {
	const time = Date.parse(value || '')
	if (Number.isNaN(time)) {
		return ''
	}
	try {
		return new Intl.DateTimeFormat(locale || 'nl', { dateStyle: 'long' }).format(
			new Date(time),
		)
	} catch {
		return new Date(time).toISOString().slice(0, 10)
	}
}

/**
 * A calendar date (a date of birth) as a long date, read in UTC so no time
 * zone moves it a day. The raw value when it does not parse.
 *
 * @param {string} value An ISO date.
 * @param {string} locale The reader's locale.
 * @return {string} The date.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-their-registered-details-req-srp-038
 */
export function calendarDate(value, locale) {
	const time = Date.parse(`${value || ''}T00:00:00Z`)
	if (Number.isNaN(time)) {
		return value || ''
	}
	try {
		return new Intl.DateTimeFormat(locale || 'nl', {
			dateStyle: 'long',
			timeZone: 'UTC',
		}).format(new Date(time))
	} catch {
		return value
	}
}

/**
 * A moment as a short date in the reader's language, or '' when it does not parse.
 *
 * @param {string} value An ISO date or date-time.
 * @param {string} locale The reader's locale.
 * @return {string} The date.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-citizen-must-work-on-their-own-case-req-srp-042
 */
export function shortDate(value, locale) {
	const time = Date.parse(value || '')
	if (Number.isNaN(time)) {
		return ''
	}
	try {
		return new Intl.DateTimeFormat(locale || 'nl', {
			dateStyle: 'short',
		}).format(new Date(time))
	} catch {
		return new Date(time).toISOString().slice(0, 10)
	}
}

/**
 * The reader's locale: the one handed in, else the page's `<html lang>`, else Dutch.
 *
 * @param {string} given The locale a caller handed in, or ''.
 * @return {string} The locale.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-see-every-case-in-one-list-req-srp-040
 */
export function readerLocale(given) {
	if (given) {
		return given
	}
	if (typeof document !== 'undefined' && document.documentElement?.lang) {
		return document.documentElement.lang
	}
	return 'nl'
}
