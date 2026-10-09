// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// THE BOARDS' LISTS (mijn-lists-follow-the-boards): a day in words, the
// "Nieuw" mark, the tabs over a list, a row's own page, and the marks of one
// subject grouped with their average. Plain functions without Vue, so
// tests/site-look/mijn-lists-follow-the-boards.spec.mjs runs them in node.
//
// @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-rows-may-read-as-the-boards-lists

import { markText, numberOf, textOf } from './displays.js'

/**
 * How many days a row with a date in its `newField` counts as new. A
 * provider that knows what the resident has seen gives a boolean instead.
 */
const NEW_DAYS = 7

/**
 * A date from a value: a day (`2026-10-02`) as that local day, else a moment.
 *
 * @param {unknown} value The value.
 * @return {Date|null} The date, or null when it is none.
 */
export function dateOf(value) {
	if (typeof value !== 'string' && typeof value !== 'number') {
		return null
	}
	const day =
		typeof value === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(value) : null
	const date = day
		? new Date(Number(day[1]), Number(day[2]) - 1, Number(day[3]))
		: new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * A day as the boards write it: "Vandaag", "Morgen", "Gisteren", else
 * "vrijdag 2 oktober" (with the year when it is not this year's).
 *
 * @param {unknown} value A date or a moment.
 * @param {Date} [today] Today.
 * @param {string} [locale] The page language.
 * @param {(key: string) => string} [tr] The translator, for the near days.
 * @return {string} The words, or '' for no date.
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/site-mijn-omgeving/spec.md#requirement-dates-read-as-words
 */
export function dayWords(value, today = new Date(), locale = 'nl', tr = null) {
	const date = dateOf(value)
	if (!date) {
		return ''
	}
	const start = (day) =>
		new Date(day.getFullYear(), day.getMonth(), day.getDate()).getTime()
	const offset = Math.round((start(date) - start(today)) / 86400000)
	const near = { 0: 'Today', 1: 'Tomorrow', '-1': 'Yesterday' }[offset]
	if (near && typeof tr === 'function') {
		return tr(near)
	}
	const options = { weekday: 'long', day: 'numeric', month: 'long' }
	if (date.getFullYear() !== today.getFullYear()) {
		options.year = 'numeric'
	}
	return new Intl.DateTimeFormat(
		String(locale || 'nl').startsWith('en') ? 'en-GB' : 'nl-NL',
		options,
	).format(date)
}

/**
 * Whether a row counts as new: its `newField` is true, or a date of the last
 * seven days.
 *
 * @param {object} row The row.
 * @param {string} [field] The block's `newField`.
 * @param {Date} [today] Today.
 * @return {boolean}
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-rows-may-read-as-the-boards-lists
 */
export function isNew(row, field, today = new Date()) {
	if (!field) {
		return false
	}
	const value = row?.[field]
	if (value === true || value === 'true') {
		return true
	}
	const date =
		typeof value === 'string' && value.length >= 10 ? dateOf(value) : null
	if (!date) {
		return false
	}
	const age = (today.getTime() - date.getTime()) / 86400000
	return age >= -1 && age <= NEW_DAYS
}

/**
 * The rows a tab shows: those whose `field` holds one of its `values`, or
 * every row for a tab without a field.
 *
 * @param {Array<object>} rows The rows.
 * @param {{field?: string, values?: Array<string>}|null} tab The chosen tab.
 * @return {Array<object>} The rows.
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
 */
export function rowsForTab(rows, tab) {
	const list = Array.isArray(rows) ? rows : []
	if (!tab || !tab.field || !Array.isArray(tab.values)) {
		return list
	}
	return list.filter((row) => tab.values.includes(String(row?.[tab.field] ?? '')))
}

/**
 * The route of the page that shows one row (`rowPage`), or '' when the block
 * names none, the page is not offered, or the row has no id.
 *
 * @param {object} row The row.
 * @param {object} block The block: `rowPage`, `rowIdField`.
 * @param {string} pageRoute The route of `rowPage`, or ''.
 * @return {string} The route.
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-row-may-open-its-own-page
 */
export function rowRoute(row, block, pageRoute) {
	if (!pageRoute) {
		return ''
	}
	const value = block?.rowIdField
		? row?.[block.rowIdField]
		: row?.id || row?.uuid || row?.['@self']?.id
	const id =
		typeof value === 'string' || typeof value === 'number' ? String(value) : ''
	return id === '' ? '' : `${pageRoute}/${encodeURIComponent(id)}`
}

/**
 * The parts `display: rows` adds for the boards' lists: the big figure, the
 * "Nieuw" mark, the small line above the title and the day in words.
 *
 * @param {object} row The row.
 * @param {object} block The block.
 * @param {object} context What the words need.
 * @param {object|null} context.collection The collection, for its value labels.
 * @param {string} context.locale The page language.
 * @param {Date} context.today Today.
 * @param {(key: string) => string} context.tr The translator.
 * @return {{value: string, isNew: boolean, eyebrow: string, dateWords: string}}
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-rows-may-read-as-the-boards-lists
 */
export function rowExtras(row, block, { collection, locale, today, tr }) {
	const number = numberOf(row?.[block?.valueField])
	const value =
		number === null
			? textOf(row, block?.valueField, collection)
			: markText(number, locale)
	const dateWords =
		block?.dateDisplay && block.dateDisplay !== 'tile'
			? dayWords(row?.[block?.dateField], today, locale, tr)
			: ''
	const eyebrowParts = [
		block?.dateDisplay === 'eyebrow' ? dateWords : '',
		textOf(row, block?.eyebrowField, collection),
	].filter((part) => part !== '')
	return {
		value,
		isNew: isNew(row, block?.newField, today),
		eyebrow: eyebrowParts.join(' · '),
		dateWords,
	}
}

/**
 * `display: chips` over one row per mark (`groupField`): one entry per
 * subject with its marks in date order, the average (weighted by
 * `weightField` when there is one), whether it is below `lowBelow`, the sub
 * line of its first row, whether a mark is new, and the row that opens its
 * page. A subject without a number shows its last word ("voldoende") as a
 * chip and its first letter as the big figure, as the board's "V".
 *
 * @param {Array<object>} rows The rows, one per mark.
 * @param {object} block The block.
 * @param {object} context What the entries need.
 * @param {object|null} context.collection The collection, for its value labels.
 * @param {string} context.locale The page language.
 * @param {Date} context.today Today.
 * @return {Array<object>} `{key, label, subtitle, marks: [{text, low}], average, averageLow, isNew, row}`.
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-marks-may-be-grouped-per-subject-with-their-average
 */
export function groupedChipRows(rows, block, { collection, locale, today }) {
	const low = numberOf(block?.lowBelow)
	const isLow = (value) => low !== null && value !== null && value < low
	const groups = new Map()
	for (const row of Array.isArray(rows) ? rows : []) {
		const key = String(row?.[block.groupField] ?? '').trim()
		if (key === '') {
			continue
		}
		if (!groups.has(key)) {
			groups.set(key, [])
		}
		groups.get(key).push(row)
	}
	const time = (row) => dateOf(row?.[block?.dateField])?.getTime() ?? 0
	return [...groups.entries()].map(([key, members]) => {
		const ordered = [...members].sort((a, b) => time(a) - time(b))
		let sum = 0
		let weights = 0
		const marks = ordered.map((row) => {
			const value = numberOf(row?.[block.valueField])
			if (value === null) {
				return {
					text: textOf(row, block.valueField, collection),
					low: false,
				}
			}
			const weight = numberOf(row?.[block?.weightField]) ?? 1
			sum += value * weight
			weights += weight
			return { text: markText(value, locale), low: isLow(value) }
		})
		const average = weights > 0 ? Math.round((sum / weights) * 10) / 10 : null
		const last = marks.length > 0 ? marks[marks.length - 1].text : ''
		return {
			key,
			label: textOf(
				ordered[0],
				block.labelField || block.groupField,
				collection,
			),
			subtitle: textOf(ordered[0], block?.subtitleField, collection),
			marks: marks.filter((mark) => mark.text !== ''),
			average:
				average === null
					? last.charAt(0).toUpperCase()
					: markText(average, locale),
			averageValue: average,
			averageLow: isLow(average),
			isNew: ordered.some((row) => isNew(row, block?.newField, today)),
			row: ordered[0],
		}
	})
}

/**
 * The summary over grouped marks: the mean of the subjects' averages, how
 * many subjects have one, and how many stand at or above `lowBelow`.
 *
 * @param {Array<object>} entries From groupedChipRows().
 * @param {string} [locale] The page language.
 * @return {{average: string, count: number, pass: number, fail: number}|null}
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-marks-may-be-grouped-per-subject-with-their-average
 */
export function chipSummary(entries, locale) {
	const counted = (Array.isArray(entries) ? entries : []).filter(
		(entry) => typeof entry.averageValue === 'number',
	)
	if (counted.length === 0) {
		return null
	}
	const mean =
		counted.reduce((sum, entry) => sum + entry.averageValue, 0) / counted.length
	const fail = counted.filter((entry) => entry.averageLow).length
	return {
		average: markText(Math.round(mean * 10) / 10, locale),
		count: counted.length,
		pass: counted.length - fail,
		fail,
	}
}
