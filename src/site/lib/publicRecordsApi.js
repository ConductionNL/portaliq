/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The public site's way to the public records routes
 * (site-member-voting-record-and-confidential-papers). Anonymous: no bearer,
 * no cookies. Free of Vue so the node tests drive it directly.
 */

/**
 * The base of the public records routes, found from the content API base.
 *
 * @param {string} apiBase The content API base, e.g. `/apps/portaliq/api/content`.
 * @return {string} The records base.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordsBase(apiBase) {
	return `${String(apiBase || '').replace(/\/api\/content\/?$/, '')}/api/public-records`
}

/**
 * Read one JSON answer; a 404 is `null` so "gone" is not an error.
 *
 * @param {string} url The URL.
 * @param {Function} [fetcher] The fetch to use.
 * @return {Promise<object|null>} The body, or null on 404.
 */
async function read(url, fetcher) {
	const doFetch = fetcher || ((...args) => window.fetch(...args))
	const response = await doFetch(url, {
		credentials: 'omit',
		headers: { Accept: 'application/json' },
	})
	if (response.status === 404) {
		return null
	}

	if (!response.ok) {
		throw new Error(`public records ${response.status}`)
	}

	return response.json()
}

/**
 * The entries of one list.
 *
 * @param {string} apiBase The content API base.
 * @param {string} app The contributing app.
 * @param {string} list The list id.
 * @param {Function} [fetcher] The fetch to use.
 * @return {Promise<Array<object>|null>} The entries, or null when the list is gone.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export async function fetchRecordList(apiBase, app, list, fetcher) {
	const body = await read(
		`${recordsBase(apiBase)}/${encodeURIComponent(app)}/${encodeURIComponent(list)}`,
		fetcher,
	)
	return body ? body.entries || [] : null
}

/**
 * One record.
 *
 * @param {string} apiBase The content API base.
 * @param {string} app The contributing app.
 * @param {string} list The list id.
 * @param {string} id The record id.
 * @param {Function} [fetcher] The fetch to use.
 * @return {Promise<object|null>} The record, or null when it is gone.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function fetchRecord(apiBase, app, list, id, fetcher) {
	return read(
		`${recordsBase(apiBase)}/${encodeURIComponent(app)}/${encodeURIComponent(list)}/${encodeURIComponent(id)}`,
		fetcher,
	)
}

/**
 * The entries whose title or subtitle holds the typed text.
 *
 * @param {Array<object>} entries The list.
 * @param {string} text What the visitor typed.
 * @return {Array<object>} The matching entries.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function matchingEntries(entries, text) {
	const needle = String(text || '')
		.trim()
		.toLowerCase()
	if (needle === '') {
		return entries
	}

	return entries.filter((entry) =>
		`${entry.title || ''} ${entry.subtitle || ''}`
			.toLowerCase()
			.includes(needle),
	)
}

/**
 * The record id in a query string (`?record=<id>`).
 *
 * @param {string} search The location's search part.
 * @return {string} The id, or ''.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordIdFrom(search) {
	return new URLSearchParams(String(search || '')).get('record') || ''
}

/**
 * A record's summary as the shared figure cards read it: one row holding
 * every card's value and detail, and per card the fields it reads. The
 * provider's detail is already a sentence ("aan 86 stemmingen"), so it is a
 * detail without a counted word.
 *
 * @param {Array<{label: string, value: string, detail?: string}>} summary The record's summary.
 * @return {{cards: Array<object>, row: object}} The KpiCards props.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordFigures(summary) {
	const cards = []
	const row = {}
	;(Array.isArray(summary) ? summary : []).forEach((card, index) => {
		const field = `figure${index}`
		row[field] = card?.value ?? ''
		const entry = { field, label: String(card?.label ?? '') }
		if (card?.detail) {
			row[`${field}Detail`] = String(card.detail)
			entry.details = [{ field: `${field}Detail`, label: '' }]
		}
		cards.push(entry)
	})

	return { cards, row }
}

/**
 * A record's columns as the shared table reads them. The column named by
 * `linkKey` links to the row's `subjectUrl` when the row has one; the table
 * keeps only a site path or an http(s) address as a link.
 *
 * @param {Array<{key: string, label: string}>} columns The record's columns.
 * @param {string} linkKey The column that links to its subject.
 * @return {{columns: Array<object>}} The CollectionTable collection.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordTableCollection(columns, linkKey) {
	return {
		columns: (Array.isArray(columns) ? columns : []).map((column) =>
			column.key === linkKey
				? {
						field: column.key,
						label: column.label,
						render: 'link',
						hrefField: 'subjectUrl',
					}
				: { field: column.key, label: column.label },
		),
	}
}

/**
 * The filters a page declares for a record's rows, each with the values the
 * rows hold in that column, in the order they first appear (board
 * PtStemgedrag: "Vergadering", "Stem"). A filter on a column the record does
 * not have, or without a label, is left out.
 *
 * @param {Array<object>} rows The record's rows.
 * @param {Array<{key: string, label: string, all?: string}>} filters The page's filters.
 * @param {Array<{key: string}>} columns The record's columns.
 * @return {Array<{key: string, label: string, all: string, options: Array<string>}>} The filters.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function recordFilters(rows, filters, columns) {
	const keys = new Set(
		(Array.isArray(columns) ? columns : []).map((column) => column?.key),
	)
	return (Array.isArray(filters) ? filters : [])
		.filter(
			(filter) =>
				filter
				&& typeof filter.key === 'string'
				&& keys.has(filter.key)
				&& typeof filter.label === 'string'
				&& filter.label !== '',
		)
		.map((filter) => ({
			key: filter.key,
			label: filter.label,
			all: typeof filter.all === 'string' ? filter.all : '',
			options: [
				...new Set(
					(Array.isArray(rows) ? rows : [])
						.map((row) => String(row?.[filter.key] ?? ''))
						.filter((value) => value !== ''),
				),
			],
		}))
}

/**
 * The rows that hold every chosen value; an empty choice keeps every row.
 *
 * @param {Array<object>} rows The record's rows.
 * @param {object} chosen The chosen value per column key.
 * @return {Array<object>} The rows.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function filteredRows(rows, chosen) {
	const wanted = Object.entries(chosen || {}).filter(([, value]) => value)
	return (Array.isArray(rows) ? rows : []).filter((row) =>
		wanted.every(([key, value]) => String(row?.[key] ?? '') === value),
	)
}

/**
 * One page of rows, and where it sits: `from` and `to` count from 1, `pages`
 * is at least 1, and a page past the end shows the last one.
 *
 * @param {Array<object>} rows The rows.
 * @param {number} page The page asked for, from 1.
 * @param {number} size The rows per page; 0 or less shows every row.
 * @return {{rows: Array<object>, page: number, pages: number, from: number, to: number, total: number}} The page.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function rowsPage(rows, page, size) {
	const all = Array.isArray(rows) ? rows : []
	const total = all.length
	const per = Number(size) > 0 ? Math.floor(Number(size)) : Math.max(total, 1)
	const pages = Math.max(1, Math.ceil(total / per))
	const current = Math.min(Math.max(1, Math.floor(Number(page) || 1)), pages)
	const start = (current - 1) * per
	const shown = all.slice(start, start + per)
	return {
		rows: shown,
		page: current,
		pages,
		from: shown.length ? start + 1 : 0,
		to: start + shown.length,
		total,
	}
}

/**
 * The initials a record's side card shows for its title: the first letter of
 * the first and the last word ("Joost van Dalen" is "JD").
 *
 * @param {string} title The record's title.
 * @return {string} One or two capitals, or ''.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function initialsOf(title) {
	const words = String(title || '')
		.trim()
		.split(/\s+/)
		.filter(Boolean)
	if (words.length === 0) {
		return ''
	}
	const first = words[0].charAt(0)
	const last = words.length > 1 ? words[words.length - 1].charAt(0) : ''
	return (first + last).toUpperCase()
}

/**
 * A page's sentence with its `{name}` placeholders filled in.
 *
 * @param {string} text The sentence ("{count} stemmingen").
 * @param {object} vars The values.
 * @return {string} The sentence.
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t3
 */
export function fillText(text, vars) {
	return String(text || '').replace(/\{(\w+)\}/g, (match, name) =>
		Object.hasOwn(vars || {}, name) ? String(vars[name]) : match,
	)
}
