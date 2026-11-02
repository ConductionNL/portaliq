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
	if (/^\d{4}-\d{2}-\d{2}/.test(text)) {
		const time = Date.parse(text)
		if (!Number.isNaN(time)) {
			return time
		}
	}
	return text.toLocaleLowerCase()
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
		.sort((a, b) => {
			if (a.value === null || b.value === null) {
				return (a.value === null) - (b.value === null) || a.index - b.index
			}
			if (a.value < b.value) {
				return -sign
			}
			if (a.value > b.value) {
				return sign
			}
			return a.index - b.index
		})
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
 * The start and end (exclusive) of a range around today.
 *
 * @param {string} range `day`, `week` or `month`.
 * @param {Date} today Today.
 * @return {{from: Date, to: Date}|null} The span, null for no range.
 */
export function rangeSpan(range, today) {
	const day = new Date(today.getFullYear(), today.getMonth(), today.getDate())
	if (range === 'day') {
		return {
			from: day,
			to: new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1),
		}
	}
	if (range === 'week') {
		// Monday is the first day of the week here, Sunday the last.
		const back = (day.getDay() + 6) % 7
		const from = new Date(
			day.getFullYear(),
			day.getMonth(),
			day.getDate() - back,
		)
		return {
			from,
			to: new Date(from.getFullYear(), from.getMonth(), from.getDate() + 7),
		}
	}
	if (range === 'month') {
		return {
			from: new Date(day.getFullYear(), day.getMonth(), 1),
			to: new Date(day.getFullYear(), day.getMonth() + 1, 1),
		}
	}
	return null
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
