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
