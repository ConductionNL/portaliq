// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The dated list's rows from its authored items (site-school-blocks):
// sorted by day, the past left out when asked, cut to the limit. Plain
// functions, so node tests them.
//
// @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-dated-list-shows-each-date-as-a-tile-or-a-label

import { dayLabel, isPast, toDate } from '../../components/mijn/dates.js'
import { authoredLink } from '../../components/mijn/links.js'

/** The tones a note may have. */
export const NOTE_TONES = ['neutral', 'positive', 'warning']

/**
 * A value as trimmed text, '' when absent.
 *
 * @param {string|number|null|undefined} value The value.
 * @return {string} The text.
 */
function trimmed(value) {
	return String(value ?? '').trim()
}

/**
 * One row of the dated list from its authored item.
 *
 * @param {object|null} item The authored item.
 * @param {string} [locale] The page language.
 * @return {object} `{date, endDate, label, title, link, meta, note, tone}`.
 */
function rowOf(item, locale) {
	return {
		date: trimmed(item?.date),
		endDate: trimmed(item?.endDate),
		label: trimmed(item?.dateLabel) || dayLabel(item?.date, item?.endDate, locale),
		title: trimmed(item?.title),
		link: item?.href ? authoredLink(item.href) : null,
		meta: trimmed(item?.meta),
		note: trimmed(item?.note),
		tone: NOTE_TONES.includes(item?.noteTone) ? item.noteTone : 'neutral',
	}
}

/**
 * The rows to draw.
 *
 * @param {Array<object>} items The authored items.
 * @param {object} options How to read them.
 * @param {boolean} options.upcomingOnly Leave out what is over.
 * @param {number} options.limit The most rows.
 * @param {string} [options.locale] The page language.
 * @param {Date} [options.now] Today, for a test.
 * @return {Array<object>} `{key, date, label, title, link, meta, note, tone}` each.
 */
export function eventRows(items, { upcomingOnly, limit, locale, now = new Date() }) {
	const max = Math.min(20, Math.max(1, Math.trunc(Number(limit)) || 6))
	return (Array.isArray(items) ? items : [])
		.map((item, index) => ({ key: index, ...rowOf(item, locale) }))
		.filter((row) => row.title !== '' && toDate(row.date) !== null)
		.filter((row) => !upcomingOnly || !isPast(row.date, row.endDate, now))
		.sort((a, b) => toDate(a.date) - toDate(b.date))
		.slice(0, max)
}
