// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// THE RECORD PAGE, WITHOUT VUE (contribution-record-page).
//
// A page that declares `record: {collection}` opens on the rows of that
// collection; picking one opens the record, and the page's other blocks show
// only what belongs to it. Everything here is presentation: the server already
// scoped every row to the subject, so narrowing to a record can only ever
// leave rows out, never add one.
//
// Also the parts of the kpi, calendar and news blocks that decide something:
// which row a kpi card reads, which items a calendar shows on which day, and
// which news items belong to the open record.
//
// Imports nothing, so tests/record-page.spec.mjs runs it as node.
//
// @spec openspec/changes/contribution-record-page/tasks.md#T2

/**
 * The id of a row, wherever the envelope keeps it.
 *
 * @param {object} row The row.
 * @return {string}
 */
function idOf(row) {
	if (!row || typeof row !== 'object') {
		return ''
	}
	return String(row.id || row.uuid || row['@self']?.id || '')
}

/**
 * The value of a record a block narrows on: its `recordKey` field, its id by default.
 *
 * @param {object|null} record The open record.
 * @param {string} [key] The record field, `id` by default.
 * @return {string}
 */
export function recordValue(record, key = 'id') {
	if (!record) {
		return ''
	}
	if (!key || key === 'id') {
		return idOf(record)
	}
	const value = record[key]
	return value === null || value === undefined ? '' : String(value)
}

/**
 * Whether a row belongs to the open record: its `recordField` equals the
 * record's value, or holds it when the field is a list.
 *
 * @param {object} row The row.
 * @param {string} field The row's record field.
 * @param {string} value The record's value.
 * @return {boolean}
 */
function belongs(row, field, value) {
	const own = row ? row[field] : undefined
	if (Array.isArray(own)) {
		return own.map(String).includes(value)
	}
	return own !== null && own !== undefined && String(own) === value
}

/**
 * Whether a row bound to groups is for one of these groups. A row that names
 * no group (a school-wide event) is for everyone.
 *
 * @param {object} row The row.
 * @param {string} field The row's group field.
 * @param {Set<string>} groups The groups that count.
 * @return {boolean}
 */
function inGroups(row, field, groups) {
	const own = row ? row[field] : undefined
	const list = (Array.isArray(own) ? own : [own]).filter(
		(value) => value !== null && value !== undefined && value !== '',
	)
	return list.length === 0 || list.some((value) => groups.has(String(value)))
}

/**
 * The rows of a block narrowed to the open record. Without a record or a
 * `recordField` the rows come back as they are; with a record whose value is
 * empty nothing belongs to it, so nothing comes back. A block that names a
 * `recordGroupsField` also keeps only the rows for the given groups (the
 * record's, or every child's without a record).
 *
 * @param {Array<object>} rows The scoped rows.
 * @param {{recordField?: string, recordKey?: string, recordGroupsField?: string}|null} scope The block or source.
 * @param {object|null} record The open record.
 * @param {Array<string>|null} [groups] The groups that count, null to skip.
 * @return {Array<object>}
 */
export function narrowToRecord(rows, scope, record, groups = null) {
	let list = Array.isArray(rows) ? rows : []
	if (scope && scope.recordGroupsField && Array.isArray(groups)) {
		const set = new Set(groups)
		list = list.filter((row) => inGroups(row, scope.recordGroupsField, set))
	}
	if (!record || !scope || !scope.recordField) {
		return list
	}
	const value = recordValue(record, scope.recordKey)
	if (value === '') {
		return []
	}
	return list.filter((row) => belongs(row, scope.recordField, value))
}

/**
 * The rows with each lookup's value written under its `as` name: the
 * `valueField` of the row in the lookup collection whose `matchField` holds
 * this row's id, narrowed to the record like a block, labelled through
 * `values`, else `fallback`.
 *
 * @param {Array<object>} rows The rows.
 * @param {Array<object>|undefined} lookups The block's lookups.
 * @param {object} store Loaded rows.
 * @param {object|null} record The open record.
 * @return {Array<object>}
 */
export function withLookups(rows, lookups, store, record) {
	if (!Array.isArray(lookups) || lookups.length === 0) {
		return rows
	}
	const indexes = lookups.map((lookup) => {
		const index = new Map()
		for (const row of narrowToRecord(
			store?.[lookup.collection]?.objects,
			lookup,
			record,
		)) {
			const key = String(row?.[lookup.matchField] ?? '')
			if (key !== '' && !index.has(key)) {
				index.set(key, row[lookup.valueField])
			}
		}
		return index
	})
	return rows.map((row) => {
		const out = { ...row }
		lookups.forEach((lookup, i) => {
			const raw = indexes[i].get(idOf(row))
			const labelled =
				raw !== undefined && raw !== null && lookup.values
					? lookup.values[String(raw)]
					: undefined
			out[lookup.as] = labelled ?? raw ?? lookup.fallback ?? ''
		})
		return out
	})
}

/**
 * The record's title: its title fields joined, else a name it carries.
 *
 * @param {object|null} record The record.
 * @param {Array<string>} [titleFields] The fields to join.
 * @return {string}
 */
export function recordTitle(record, titleFields) {
	if (!record) {
		return ''
	}
	const fields =
		Array.isArray(titleFields) && titleFields.length > 0
			? titleFields
			: ['name', 'title', 'givenName']
	return fields
		.map((field) => record[field])
		.filter((value) => typeof value === 'string' && value.trim() !== '')
		.join(' ')
}

/**
 * The row a kpi block reads: the one with the highest (or lowest) value of
 * `pick.field`, the first row without `pick`, null without rows.
 *
 * @param {Array<object>} rows The narrowed rows.
 * @param {{field: string, direction?: string}|null} [pick] The pick rule.
 * @return {object|null}
 */
export function pickRow(rows, pick) {
	const list = Array.isArray(rows) ? rows.filter(Boolean) : []
	if (list.length === 0) {
		return null
	}
	if (!pick || !pick.field) {
		return list[0]
	}
	const sign = pick.direction === 'asc' ? 1 : -1
	return [...list].sort((a, b) => {
		const x = a[pick.field]
		const y = b[pick.field]
		if (x === y) {
			return 0
		}
		if (x === null || x === undefined) {
			return 1
		}
		if (y === null || y === undefined) {
			return -1
		}
		return (x < y ? -1 : 1) * sign
	})[0]
}

/**
 * A card's figure as a person reads it: a number in the page's language, a
 * dash when the row holds none.
 *
 * @param {object|null} row The row.
 * @param {string} field The field.
 * @param {string} locale The language.
 * @return {string}
 */
export function figure(row, field, locale) {
	const value = row ? row[field] : undefined
	if (value === null || value === undefined || value === '') {
		return '–'
	}
	const number = Number(value)
	if (Number.isFinite(number)) {
		try {
			return number.toLocaleString(locale || 'nl')
		} catch {
			return String(number)
		}
	}
	return String(value)
}

const DATE_ONLY = /^\d{4}-\d{2}-\d{2}$/

/**
 * A date or moment as a Date, or null when it reads as neither. A bare date
 * is the start of that day in the reader's time zone, not UTC midnight.
 *
 * @param {unknown} value The value.
 * @return {Date|null}
 */
export function toDate(value) {
	if (typeof value !== 'string' || value === '') {
		return null
	}
	if (DATE_ONLY.test(value)) {
		const [y, m, d] = value.split('-').map(Number)
		return new Date(y, m - 1, d)
	}
	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? null : date
}

/**
 * The day a moment falls on, as `YYYY-MM-DD` in the reader's time zone.
 *
 * @param {Date} date The moment.
 * @return {string}
 */
export function dayKey(date) {
	const pad = (n) => String(n).padStart(2, '0')
	return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * One calendar item from a row (or an element of a row's list).
 *
 * @param {object} data The row or element.
 * @param {object} fields `startField`, `endField`, `titleField`, `title`.
 * @param {string} kind The kind label.
 * @param {string} key A stable key.
 * @return {object|null}
 */
function itemOf(data, fields, kind, key) {
	if (!data || typeof data !== 'object') {
		return null
	}
	const start = toDate(data[fields.startField])
	const own = fields.titleField ? data[fields.titleField] : ''
	const title =
		typeof own === 'string' && own.trim() !== '' ? own : fields.title || ''
	if (!start || title.trim() === '') {
		return null
	}
	const declaredEnd = fields.endField ? toDate(data[fields.endField]) : null
	const end = declaredEnd && declaredEnd >= start ? declaredEnd : start
	return {
		key,
		start,
		end,
		title: title.trim(),
		kind: kind || '',
		allDay: DATE_ONLY.test(String(data[fields.startField])),
	}
}

/**
 * The items of a calendar block: every source's rows, narrowed to the open
 * record, each row (or each element of its `expand` list) one item, in
 * date order.
 *
 * @param {object} block The calendar block.
 * @param {object} store Loaded rows, `{[collectionId]: {objects}}`.
 * @param {object|null} record The open record.
 * @param {Array<string>|null} [groups] The groups that count, null to skip.
 * @return {Array<object>}
 */
export function calendarItems(block, store, record, groups = null) {
	const items = []
	;(block?.sources || []).forEach((source, s) => {
		const rows = narrowToRecord(
			store?.[source.collection]?.objects,
			source,
			record,
			groups,
		)
		rows.forEach((row, r) => {
			const only = source.only
			if (only && !only.in.includes(String(row?.[only.field] ?? ''))) {
				return
			}
			const base = `${s}:${idOf(row) || r}`
			if (source.expand) {
				const list = Array.isArray(row?.[source.expand.field])
					? row[source.expand.field]
					: []
				list.forEach((element, e) => {
					const item = itemOf(
						element,
						source.expand,
						source.kind,
						`${base}:${e}`,
					)
					if (item) {
						items.push(item)
					}
				})
				return
			}
			const item = itemOf(row, source, source.kind, base)
			if (item) {
				items.push(item)
			}
		})
	})
	const seen = new Set()
	return items
		.sort((a, b) => a.start - b.start || a.title.localeCompare(b.title))
		.filter((item) => {
			// The same holiday held on two report periods is one item.
			const same = `${dayKey(item.start)}|${dayKey(item.end)}|${item.title}|${item.kind}`
			if (seen.has(same)) {
				return false
			}
			seen.add(same)
			return true
		})
}

/**
 * The items that end today or later.
 *
 * @param {Array<object>} items The items.
 * @param {Date} today Today.
 * @return {Array<object>}
 */
export function upcomingItems(items, today) {
	const from = new Date(today.getFullYear(), today.getMonth(), today.getDate())
	return (items || []).filter((item) => item.end >= from)
}

/**
 * The items on one day: those that start on or before it and end on or after it.
 *
 * @param {Array<object>} items The items.
 * @param {Date} day The day.
 * @return {Array<object>}
 */
export function itemsOnDay(items, day) {
	const key = dayKey(day)
	return (items || []).filter(
		(item) => dayKey(item.start) <= key && dayKey(item.end) >= key,
	)
}

/**
 * The weeks of a month, Monday first: each week seven days, a day outside
 * the month is null.
 *
 * @param {number} year The year.
 * @param {number} month The month, 0 for January.
 * @return {Array<Array<Date|null>>}
 */
export function monthWeeks(year, month) {
	const first = new Date(year, month, 1)
	const offset = (first.getDay() + 6) % 7
	const days = new Date(year, month + 1, 0).getDate()
	const cells = []
	for (let i = 0; i < offset; i++) {
		cells.push(null)
	}
	for (let d = 1; d <= days; d++) {
		cells.push(new Date(year, month, d))
	}
	while (cells.length % 7 !== 0) {
		cells.push(null)
	}
	const weeks = []
	for (let i = 0; i < cells.length; i += 7) {
		weeks.push(cells.slice(i, i + 7))
	}
	return weeks
}

/**
 * Items grouped per month, in order: `[{key: 'YYYY-MM', date, items}]`.
 *
 * @param {Array<object>} items The items, in date order.
 * @return {Array<{key: string, date: Date, items: Array<object>}>}
 */
export function itemsByMonth(items) {
	const groups = []
	for (const item of items || []) {
		const key = dayKey(item.start).slice(0, 7)
		let group = groups[groups.length - 1]
		if (!group || group.key !== key) {
			group = {
				key,
				date: new Date(item.start.getFullYear(), item.start.getMonth(), 1),
				items: [],
			}
			groups.push(group)
		}
		group.items.push(item)
	}
	return groups
}

/**
 * The group ids of the open record, from the contribution's
 * `guardianAudience.groups` collection: the rows that link to the record
 * through that collection's `groupByField`.
 *
 * @param {object|null} contribution The contribution.
 * @param {object} store Loaded rows.
 * @param {object|null} record The open record.
 * @return {Array<string>}
 */
export function recordGroups(contribution, store, record) {
	const groups = contribution?.guardianAudience?.groups
	if (!record || !groups || !groups.collection || !groups.field) {
		return []
	}
	const collection = (contribution.collections || []).find(
		(c) => c && c.id === groups.collection,
	)
	const link = collection?.groupByField || 'learnerRef'
	const id = idOf(record)
	return (store?.[groups.collection]?.objects || [])
		.filter((row) => String(row?.[link] ?? '') === id)
		.map((row) => String(row[groups.field] ?? ''))
		.filter((value) => value !== '')
}

/**
 * The groups of every child: each row of the contribution's
 * `guardianAudience.groups` collection, for a page without an open record.
 *
 * @param {object|null} contribution The contribution.
 * @param {object} store Loaded rows.
 * @return {Array<string>}
 */
export function allGroups(contribution, store) {
	const groups = contribution?.guardianAudience?.groups
	if (!groups || !groups.collection || !groups.field) {
		return []
	}
	return [
		...new Set(
			(store?.[groups.collection]?.objects || [])
				.map((row) => String(row?.[groups.field] ?? ''))
				.filter((value) => value !== ''),
		),
	]
}

/**
 * The news items that belong to the open record: an item whose target names
 * the record's school, one of its groups, or the record itself. An item that
 * carries no target is the subject's own feed and stays. Without a record
 * every item stays.
 *
 * @param {Array<object>} feed The subject's news feed.
 * @param {object|null} record The open record.
 * @param {object|null} contribution The contribution (its `guardianAudience`).
 * @param {Array<string>} groups The record's group ids.
 * @return {Array<object>}
 */
export function newsForRecord(feed, record, contribution, groups) {
	const items = Array.isArray(feed) ? feed : []
	if (!record) {
		return items
	}
	const schoolField = contribution?.guardianAudience?.schoolField || ''
	const school = schoolField ? String(record[schoolField] ?? '') : ''
	const id = idOf(record)
	const groupSet = new Set(groups || [])
	return items.filter((item) => {
		const target = item?.target
		if (!target || typeof target !== 'object') {
			return true
		}
		if (school !== '' && String(target.schoolRef ?? '') === school) {
			return true
		}
		if (Array.isArray(target.childRefs) && target.childRefs.includes(id)) {
			return true
		}
		return (
			Array.isArray(target.groupRefs)
			&& target.groupRefs.some((group) => groupSet.has(group))
		)
	})
}
