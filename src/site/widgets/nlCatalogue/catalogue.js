// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The plain logic of the catalogue widget (portal-public-catalogue): the
// query it starts with, a facet choice turned on or off, what a result card
// shows and where it links, and the words of the result count. No Vue, so
// tests/portal-public-catalogue.spec.mjs runs it as node.
//
// @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue

import { authoredLink } from '../../components/mijn/links.js'

/** The sorts a visitor may choose, in the order the menu shows them. */
export const SORTS = ['relevance', 'date', 'dateDesc', 'title']

/** The words of the catalogue widget, in Dutch and English. */
export const strings = {
	nl: {
		search: 'Zoeken',
		searchIn: 'Zoek in het aanbod',
		filters: 'Filters',
		clear: 'Filters wissen',
		sort: 'Sorteren',
		relevance: 'Beste match',
		date: 'Eerste datum eerst',
		dateDesc: 'Nieuwste eerst',
		title: 'Op naam',
		loading: 'De resultaten worden geladen.',
		failed: 'Zoeken lukt nu niet. Probeer het later opnieuw.',
		none: 'Niets gevonden. Probeer een ander woord of minder filters.',
		count: '{count} resultaten',
		countOne: '1 resultaat',
		countFor: '{count} resultaten voor "{q}"',
		countOneFor: '1 resultaat voor "{q}"',
		news: 'Nieuws',
		previous: 'Vorige',
		next: 'Volgende',
		page: 'Pagina {page}',
		pages: "Pagina's",
	},
	en: {
		search: 'Search',
		searchIn: 'Search the catalogue',
		filters: 'Filters',
		clear: 'Clear filters',
		sort: 'Sort',
		relevance: 'Best match',
		date: 'Earliest date first',
		dateDesc: 'Newest first',
		title: 'By name',
		loading: 'Loading the results.',
		failed: 'Search is not available right now. Please try again later.',
		none: 'Nothing found. Try another word or fewer filters.',
		count: '{count} results',
		countOne: '1 result',
		countFor: '{count} results for "{q}"',
		countOneFor: '1 result for "{q}"',
		news: 'News',
		previous: 'Previous',
		next: 'Next',
		page: 'Page {page}',
		pages: 'Pages',
	},
}

/**
 * A word of the widget in the page language, with its placeholders filled.
 *
 * @param {string} lang `nl` or `en`.
 * @param {string} key The key.
 * @param {object} [vars] The placeholders.
 * @return {string}
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export function word(lang, key, vars = {}) {
	let text = (strings[lang === 'en' ? 'en' : 'nl'] || strings.nl)[key] || key
	for (const [name, value] of Object.entries(vars)) {
		text = text.split(`{${name}}`).join(String(value))
	}
	return text
}

/**
 * The words searched for when the page opens: the header search hands its
 * term over as `_search` on the address.
 *
 * @param {string} search The address's query string.
 * @return {string}
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export function initialQuery(search) {
	try {
		return (new URLSearchParams(search || '').get('_search') || '').trim()
	} catch {
		return ''
	}
}

/**
 * The facet choices with one value turned on or off; a facet without values
 * is left out.
 *
 * @param {Record<string, Array<string>>} filters The choices.
 * @param {string} label The facet.
 * @param {string} value The value.
 * @return {Record<string, Array<string>>}
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export function toggleFilter(filters, label, value) {
	const next = { ...(filters || {}) }
	const values = new Set(next[label] || [])
	if (values.has(value)) {
		values.delete(value)
	} else {
		values.add(value)
	}
	if (values.size === 0) {
		delete next[label]
	} else {
		next[label] = [...values]
	}
	return next
}

/**
 * The result count in words.
 *
 * @param {string} lang The page language.
 * @param {number} total How many.
 * @param {string} q The words searched for.
 * @param {string} [countLabel] An authored count, "{count} cursussen".
 * @return {string}
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export function countText(lang, total, q, countLabel = '') {
	if (countLabel && !q) {
		return countLabel.split('{count}').join(String(total))
	}
	if (q) {
		return word(lang, total === 1 ? 'countOneFor' : 'countFor', {
			count: total,
			q,
		})
	}
	return word(lang, total === 1 ? 'countOne' : 'count', { count: total })
}

/**
 * What one result card shows, and where it links: an app's own address, or
 * for a news item the article route with its id.
 *
 * @param {object} item The item.
 * @param {object} options How to read it.
 * @param {string} options.lang The page language.
 * @param {string} options.newsRoute The route of the news article page.
 * @return {{key: string, kind: string, title: string, summary: string, date: string, meta: Array<string>, note: string, tone: string, badge: string, link: object|null}}
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-catalogue-block-searches-and-filters-the-portals-public-catalogue
 */
export function resultCard(item, { lang, newsRoute }) {
	const isNews = item?.type === 'news'
	let href = String(item?.href || '')
	if (isNews && item?.newsId) {
		href = `${String(newsRoute || '/nieuws').replace(/\/$/, '')}/${encodeURIComponent(item.newsId)}`
	}
	return {
		key: String(item?.id || ''),
		kind: isNews ? word(lang, 'news') : String(item?.kind || ''),
		title: String(item?.title || ''),
		summary: String(item?.summary || ''),
		date: String(item?.date || ''),
		meta: Array.isArray(item?.meta) ? item.meta : [],
		note: String(item?.note || ''),
		tone: ['positive', 'warning'].includes(item?.noteTone)
			? item.noteTone
			: 'neutral',
		badge: String(item?.badge || ''),
		link: href ? authoredLink(href) : null,
	}
}

/**
 * The catalogue's dated items as the rows of a dated list (`nlEventList`
 * with a `source`): `{date, endDate, dateLabel, title, href, meta, note,
 * noteTone}`.
 *
 * @param {Array<object>} items The catalogue items.
 * @return {Array<object>}
 * @spec openspec/changes/portal-public-catalogue/specs/portaliq-cms/spec.md#requirement-a-dated-list-may-fill-itself-from-the-catalogue
 */
export function eventItemsOf(items) {
	return (Array.isArray(items) ? items : [])
		.filter((item) => item?.date && item?.title)
		.map((item) => ({
			date: String(item.date).slice(0, 10),
			endDate: item.endDate ? String(item.endDate).slice(0, 10) : '',
			dateLabel: item.dateLabel || '',
			title: item.title,
			href: item.href || '',
			meta: Array.isArray(item.meta) ? item.meta.join(' · ') : '',
			note: item.note || '',
			noteTone: item.noteTone || 'neutral',
		}))
}

/**
 * The catalogue query a page block's `source` stands for: `{app, kind,
 * categories[], limit | range}` for a dated list, plus `filters{}` for a
 * table. The kind is the index's `type`. Anything that is not text is left out.
 *
 * @param {object|null} source The block's declared source.
 * @return {object|null} The query, or null without an app and a kind.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-2
 */
export function sourceQuery(source) {
	const app = typeof source?.app === 'string' ? source.app.trim() : ''
	const kind = typeof source?.kind === 'string' ? source.kind.trim() : ''
	if (app === '' || kind === '') {
		return null
	}
	const query = { app, types: [kind], sort: 'date' }
	const categories = Array.isArray(source.categories)
		? source.categories.filter((c) => typeof c === 'string' && c !== '')
		: []
	if (categories.length > 0) {
		query.categories = categories
	}
	if (source.range === 'schoolYear') {
		query.range = 'schoolYear'
	}
	const limit = Math.trunc(Number(source.limit))
	query.limit = limit >= 1 ? Math.min(limit, 50) : 20
	const filters = {}
	for (const [label, values] of Object.entries(source.filters || {})) {
		const list = (Array.isArray(values) ? values : [values]).filter(
			(value) => typeof value === 'string' && value !== '',
		)
		if (list.length > 0) {
			filters[label] = list
		}
	}
	if (Object.keys(filters).length > 0) {
		query.filters = filters
	}
	return query
}

/**
 * A table's header and rows from catalogue items: one column per declared
 * column (`{key, label}`), each cell the item's cell of that key.
 *
 * @param {Array<object>} items The catalogue items.
 * @param {Array<{key: string, label: string}>} columns The columns the block chose.
 * @return {{columns: Array<string>, rows: Array<Array<string>>}} The table.
 * @spec openspec/changes/editor-blocks-read-public-app-data/tasks.md#task-3
 */
export function tableOf(items, columns) {
	const chosen = (Array.isArray(columns) ? columns : [])
		.map((column) =>
			typeof column === 'string'
				? { key: column, label: column }
				: {
						key: String(column?.key ?? ''),
						label: String(column?.label ?? column?.key ?? ''),
					},
		)
		.filter((column) => column.key !== '')
	return {
		columns: chosen.map((column) => column.label),
		rows: (Array.isArray(items) ? items : []).map((item) =>
			chosen.map((column) => String(item?.cells?.[column.key] ?? '')),
		),
	}
}
