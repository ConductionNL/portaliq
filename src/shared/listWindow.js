// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// Which rows a list block shows (site-mijn-omgeving-components REQ-SMO-021):
// a `collection` block's declared order (`sort`) and its first rows
// (`limit`), and the part of a calendar a `range` asks for (today, this
// week from Monday to Sunday, this month). Imports nothing, so
// tests/mijn-lists.spec.mjs runs it as node.
//
// @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021

/**
 * A value to compare: a date as its time, a number as itself, else its text.
 *
 * @param {unknown} value The field's value.
 * @return {number|string|null} The comparable value, null when empty.
 */
function comparable(value) {
	if (value === undefined || value === null || value === '') {
		return null
	}
	if (typeof value === 'number') {
		return value
	}
	const text = String(value)
	return dateTimeOf(text) ?? text.toLocaleLowerCase()
}

/**
 * @param {string} text A value as text.
 * @return {number|null} Its time when it starts with a readable date, else null.
 */
function dateTimeOf(text) {
	if (!/^\d{4}-\d{2}-\d{2}/.test(text)) {
		return null
	}
	const time = Date.parse(text)
	return Number.isNaN(time) ? null : time
}

/**
 * Rows without a value come last; else the order they came in.
 *
 * @param {{value: unknown, index: number}} a One row's entry.
 * @param {{value: unknown, index: number}} b The other's.
 * @return {number} The order.
 */
function emptiesLast(a, b) {
	return (a.value === null) - (b.value === null) || a.index - b.index
}

/**
 * @param {{value: unknown}} a One row's entry.
 * @param {{value: unknown}} b The other's.
 * @return {number} -1, 1, or 0 when neither value comes first.
 */
function valueOrder(a, b) {
	if (a.value < b.value) {
		return -1
	}
	return a.value > b.value ? 1 : 0
}

/**
 * @param {number} sign 1 for ascending, -1 for descending.
 * @return {(a: object, b: object) => number} The comparison of two row entries.
 */
function byValue(sign) {
	return (a, b) =>
		a.value === null || b.value === null
			? emptiesLast(a, b)
			: valueOrder(a, b) * sign || a.index - b.index
}

/**
 * The rows in the declared order; rows without a value come last, either way.
 *
 * @param {Array<object>} rows The rows.
 * @param {{field: string, direction: string}|undefined} sort The order.
 * @return {Array<object>} A sorted copy.
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
export function sortRows(rows, sort) {
	const list = Array.isArray(rows) ? [...rows] : []
	if (!sort || !sort.field) {
		return list
	}
	const sign = sort.direction === 'desc' ? -1 : 1
	return list
		.map((row, index) => ({ row, index, value: comparable(row?.[sort.field]) }))
		.sort(byValue(sign))
		.map(({ row }) => row)
}

/**
 * The order a table reads in: the block's own `sort`, else its collection's
 * `defaultSort`, else none (the order the rows arrived in).
 *
 * @param {object|undefined} block The block (`sort`).
 * @param {object|undefined} collection Its collection (`defaultSort`).
 * @return {{field: string, direction: string}|undefined} The order.
 * @spec openspec/changes/site-tables-read-in-their-declared-order/specs/portal-contribution-contract/spec.md#requirement-a-table-reads-in-its-collections-default-order
 */
export function listOrder(block, collection) {
	if (block?.sort?.field) {
		return block.sort
	}
	return collection?.defaultSort?.field ? collection.defaultSort : undefined
}

/**
 * The rows without the first `skip` of them (collection-skip): a list that
 * a highlight above already opens with its first row.
 *
 * @param {Array<object>} rows The ordered rows.
 * @param {object} block The block (`skip`).
 * @return {Array<object>}
 * @spec openspec/changes/collection-skip/specs/portal-contribution-contract/spec.md#requirement-a-collection-block-may-leave-out-its-first-rows
 */
export function skipRows(rows, block) {
	const skip = Number.isInteger(block?.skip) && block.skip >= 1 ? block.skip : 0
	return skip === 0 ? rows : rows.slice(skip)
}

/**
 * The rows a collection block shows: sorted, without the first `skip`
 * (collection-skip), at most its limit, and whether there are more.
 *
 * @param {Array<object>} rows The rows.
 * @param {object} block The block (`sort`, `limit`).
 * @return {{rows: Array<object>, more: boolean}}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
export function windowRows(rows, block) {
	const sorted = skipRows(sortRows(rows, block?.sort), block)
	const limit =
		Number.isInteger(block?.limit) && block.limit >= 1 ? block.limit : 0
	if (limit === 0) {
		return { rows: sorted, more: false }
	}
	return { rows: sorted.slice(0, limit), more: sorted.length > limit }
}

/**
 * @param {Date} day A day at midnight.
 * @return {{from: Date, to: Date}} That day.
 */
function daySpan(day) {
	return {
		from: day,
		to: new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1),
	}
}

/**
 * Monday is the first day of the week here, Sunday the last.
 *
 * @param {Date} day A day at midnight.
 * @return {{from: Date, to: Date}} Its week.
 */
function weekSpan(day) {
	const back = (day.getDay() + 6) % 7
	const from = new Date(day.getFullYear(), day.getMonth(), day.getDate() - back)
	return {
		from,
		to: new Date(from.getFullYear(), from.getMonth(), from.getDate() + 7),
	}
}

/**
 * @param {Date} day A day at midnight.
 * @return {{from: Date, to: Date}} Its month.
 */
function monthSpan(day) {
	return {
		from: new Date(day.getFullYear(), day.getMonth(), 1),
		to: new Date(day.getFullYear(), day.getMonth() + 1, 1),
	}
}

/** The span each range covers around a day. */
const RANGE_SPANS = new Map([
	['day', daySpan],
	['week', weekSpan],
	['month', monthSpan],
])

/**
 * The start and end (exclusive) of a range around today.
 *
 * @param {string} range `day`, `week` or `month`.
 * @param {Date} today Today.
 * @return {{from: Date, to: Date}|null} The span, null for no range.
 */
export function rangeSpan(range, today) {
	const span = RANGE_SPANS.get(range)
	return span
		? span(new Date(today.getFullYear(), today.getMonth(), today.getDate()))
		: null
}

/**
 * The calendar items within a range: those that start before its end and
 * end on or after its start. Without a range every item stays.
 *
 * @param {Array<{start: Date, end: Date}>} items The items.
 * @param {string|undefined} range `day`, `week` or `month`.
 * @param {Date} today Today.
 * @return {Array<object>}
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-contributed-page-may-use-the-tasks-inbox-cases-steps-documents-and-timeline-blocks-req-smo-021
 */
export function itemsInRange(items, range, today) {
	const span = rangeSpan(range, today || new Date())
	const list = Array.isArray(items) ? items : []
	if (!span) {
		return list
	}
	return list.filter(
		(item) => item.start < span.to && (item.end || item.start) >= span.from,
	)
}
