// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of a calendar block drawn as a timetable
// (calendar-timetable-display): the days a week offers as tiles, the rows of
// one day with the breaks between them, and the one line that sums the day
// up. No Vue, so tests/calendar-timetable.spec.mjs runs it as node.
//
// @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable

import { dayKey } from '../../../shared/recordPage.js'

/** A gap shorter than this many minutes is no break. */
export const BREAK_FROM_MINUTES = 5

const MINUTE_MS = 60 * 1000

/**
 * The days a week offers as tiles: Monday to Friday of the week `today` falls
 * in, and Saturday or Sunday only when an item falls on them.
 *
 * @param {Array<object>} items The calendar items.
 * @param {Date} today Today.
 * @return {Array<{key: string, date: Date, count: number}>}
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export function weekDays(items, today) {
	const day = new Date(today.getFullYear(), today.getMonth(), today.getDate())
	const monday = new Date(
		day.getFullYear(),
		day.getMonth(),
		day.getDate() - ((day.getDay() + 6) % 7),
	)
	const list = Array.isArray(items) ? items : []
	const days = []
	for (let i = 0; i < 7; i++) {
		const date = new Date(
			monday.getFullYear(),
			monday.getMonth(),
			monday.getDate() + i,
		)
		const key = dayKey(date)
		const count = list.filter((item) => dayKey(item.start) === key).length
		if (i < 5 || count > 0) {
			days.push({ key, date, count })
		}
	}
	return days
}

/**
 * The day a week timetable opens on: today when it is one of the tiles, else
 * the first tile with something on it, else the first tile.
 *
 * @param {Array<{key: string, count: number}>} days The tiles.
 * @param {Date} today Today.
 * @return {string} The day key.
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export function openingDay(days, today) {
	const key = dayKey(today)
	if (days.some((day) => day.key === key)) {
		return key
	}
	const busy = days.find((day) => day.count > 0)
	return (busy || days[0] || { key }).key
}

/**
 * The rows of one day, in time order: each item numbered (a cancelled one
 * keeps its number, so the numbers stay the hours of the day), and a break
 * between two items that are at least BREAK_FROM_MINUTES apart. The first
 * item that is not cancelled is marked `first`.
 *
 * @param {Array<object>} items The calendar items.
 * @param {string} key The day, `YYYY-MM-DD`.
 * @return {Array<object>} `{type: 'item', key, number, first, item}` or `{type: 'break', key, from, to, minutes}`.
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export function dayRows(items, key) {
	const onDay = (Array.isArray(items) ? items : [])
		.filter((item) => item.allDay !== true && dayKey(item.start) === key)
		.sort((a, b) => a.start - b.start)
	const rows = []
	let firstMarked = false
	let previousEnd = null
	onDay.forEach((item, i) => {
		if (previousEnd) {
			const minutes = Math.round((item.start - previousEnd) / MINUTE_MS)
			if (minutes >= BREAK_FROM_MINUTES) {
				rows.push({
					type: 'break',
					key: `break:${item.key}`,
					from: previousEnd,
					to: item.start,
					minutes,
				})
			}
		}
		const first = !firstMarked && !item.cancelled
		firstMarked = firstMarked || first
		rows.push({ type: 'item', key: item.key, number: i + 1, first, item })
		if (!previousEnd || item.end > previousEnd) {
			previousEnd = item.end
		}
	})
	return rows
}

/**
 * What one line says about a day: how many items, how many changed or
 * cancelled, and when the last item that still takes place ends.
 *
 * @param {Array<object>} rows The rows from dayRows().
 * @return {{count: number, changes: number, endsAt: Date|null}}
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export function daySummary(rows) {
	const items = (rows || [])
		.filter((row) => row.type === 'item')
		.map((row) => row.item)
	const held = items.filter((item) => !item.cancelled)
	const endsAt = held.reduce(
		(last, item) => (!last || item.end > last ? item.end : last),
		null,
	)
	return {
		count: items.length,
		changes: items.filter((item) => item.cancelled || item.status).length,
		endsAt,
	}
}

/**
 * A time as a Dutch reader writes it ("08.30") or an English one ("08:30").
 *
 * @param {Date} date The moment.
 * @param {string} [locale] The page language.
 * @return {string}
 * @spec openspec/changes/calendar-timetable-display/specs/portal-contribution-contract/spec.md#requirement-a-calendar-block-may-draw-a-day-as-a-timetable
 */
export function clockOf(date, locale = 'nl') {
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
		return ''
	}
	const pad = (n) => String(n).padStart(2, '0')
	const glue = String(locale || 'nl')
		.toLowerCase()
		.startsWith('en')
		? ':'
		: '.'
	return `${pad(date.getHours())}${glue}${pad(date.getMinutes())}`
}
