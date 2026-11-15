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
		all: 'Alles',
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
		all: 'All',
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
	const href = cardHref(item, newsRoute)
	return {
		key: textOf(item?.id),
		kind: cardKind(item, lang),
		title: textOf(item?.title),
		summary: textOf(item?.summary),
		date: textOf(item?.date),
		meta: Array.isArray(item?.meta) ? item.meta : [],
		note: textOf(item?.note),
		tone: noteToneOf(item),
		badge: textOf(item?.badge),
		link: href ? authoredLink(href) : null,
	}
}

/**
 * @param {unknown} value A field of an item.
 * @return {string} The value as text, or '' for none.
 */
function textOf(value) {
	return String(value || '')
}

/**
 * @param {object|null} item The item.
 * @param {string} lang The page language.
 * @return {string} "News" for a news item, else the item's own kind.
 */
function cardKind(item, lang) {
	return item?.type === 'news' ? word(lang, 'news') : textOf(item?.kind)
}

/**
 * Where a card links: the article route with its id for a news item, else
 * the app's own address.
 *
 * @param {object|null} item The item.
 * @param {string} newsRoute The route of the news article page.
 * @return {string} The address, or ''.
 */
function cardHref(item, newsRoute) {
	if (item?.type === 'news' && item?.newsId) {
		return `${String(newsRoute || '/nieuws').replace(/\/$/, '')}/${encodeURIComponent(item.newsId)}`
	}
	return textOf(item?.href)
}

/** The note tones a card draws; any other is neutral. */
const NOTE_TONES = ['positive', 'warning']

/**
 * @param {object|null} item The item.
 * @return {string} The tone of its note.
 */
function noteToneOf(item) {
	return NOTE_TONES.includes(item?.noteTone) ? item.noteTone : 'neutral'
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
		.map(eventRowOf)
}

/**
 * @param {unknown} value A date or a moment.
 * @return {string} Its day (`YYYY-MM-DD`), or '' for none.
 */
function dayOf(value) {
	return value ? String(value).slice(0, 10) : ''
}

/**
 * @param {unknown} meta An item's meta lines.
 * @return {string} The lines on one line, or ''.
 */
function joinedMeta(meta) {
	return Array.isArray(meta) ? meta.join(' · ') : ''
}

/**
 * One dated catalogue item as a row of a dated list.
 *
 * @param {object} item The item, with a date and a title.
 * @return {object} The row.
 */
function eventRowOf(item) {
	return {
		date: dayOf(item.date),
		endDate: dayOf(item.endDate),
		dateLabel: item.dateLabel || '',
		title: item.title,
		href: item.href || '',
		meta: joinedMeta(item.meta),
		note: item.note || '',
		noteTone: item.noteTone || 'neutral',
	}
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
	const app = trimmedText(source?.app)
	const kind = trimmedText(source?.kind)
	if (app === '' || kind === '') {
		return null
	}
	return { app, types: [kind], sort: 'date', ...sourceScope(source) }
}

/**
 * The part of a source's query that narrows it: its categories, the school
 * year, the number of rows and its filters, each only when declared.
 *
 * @param {object} source The block's declared source.
 * @return {object} The query fields.
 */
function sourceScope(source) {
	return {
		...whenFilled('categories', filledTexts(source.categories)),
		...(source.range === 'schoolYear' ? { range: 'schoolYear' } : {}),
		limit: sourceLimit(source.limit),
		...whenFilled('filters', sourceFilters(source.filters)),
	}
}

/**
 * @param {string} name A query field.
 * @param {Array|object} value Its list or map.
 * @return {object} The field with its value, or nothing when the value is empty.
 */
function whenFilled(name, value) {
	return Object.keys(value).length > 0 ? { [name]: value } : {}
}

/**
 * @param {unknown} value A declared value.
 * @return {string} The value trimmed when it is text, else ''.
 */
function trimmedText(value) {
	return typeof value === 'string' ? value.trim() : ''
}

/**
 * @param {unknown} values A declared list.
 * @return {Array<string>} Its non-empty texts, or none when it is no list.
 */
function filledTexts(values) {
	return Array.isArray(values)
		? values.filter((value) => typeof value === 'string' && value !== '')
		: []
}

/**
 * @param {unknown} limit The declared number of rows.
 * @return {number} The number, at most 50, or 20 when none is declared.
 */
function sourceLimit(limit) {
	const rows = Math.trunc(Number(limit))
	return rows >= 1 ? Math.min(rows, 50) : 20
}

/**
 * The declared filters as lists of their non-empty texts, without the empty
 * ones.
 *
 * @param {object|undefined} declared The filters by label: a value or a list.
 * @return {Record<string, Array<string>>} The filters.
 */
function sourceFilters(declared) {
	const filters = {}
	for (const [label, values] of Object.entries(declared || {})) {
		const list = filledTexts(Array.isArray(values) ? values : [values])
		if (list.length > 0) {
			filters[label] = list
		}
	}
	return filters
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
		.map(tableColumnOf)
		.filter((column) => column.key !== '')
	return {
		columns: chosen.map((column) => column.label),
		rows: (Array.isArray(items) ? items : []).map((item) =>
			chosen.map((column) => String(item?.cells?.[column.key] ?? '')),
		),
	}
}

/**
 * @param {string|{key: string, label: string}|null} column A declared column.
 * @return {{key: string, label: string}} The column, its key as its label for a bare key.
 */
function tableColumnOf(column) {
	return typeof column === 'string'
		? { key: column, label: column }
		: {
				key: String(column?.key ?? ''),
				label: String(column?.label ?? column?.key ?? ''),
			}
}

/** How a facet may be chosen from: several values, one value, or a menu. */
export const FACET_CONTROLS = ['checkbox', 'radio', 'select']

/**
 * The facets the block asks the server to add besides the ones the items
 * declare: one by kind ("Soort": Nieuws, Nieuwsbrief, ...) and one by a news
 * item's audience ("Voor wie"). The word for a news item is the page's own.
 *
 * @param {object} options The block's choices.
 * @param {string} [options.kindFacet] The label of the kind facet; '' for none.
 * @param {string} [options.audienceFacet] The label of the audience facet; '' for none.
 * @param {string} options.lang The page language.
 * @return {{kind?: string, news?: string, audience?: string}} Empty when nothing is asked.
 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portal-public-catalogue/spec.md#requirement-a-catalogue-may-filter-by-kind-and-by-audience
 */
export function facetsByOf({ kindFacet = '', audienceFacet = '', lang }) {
	const out = {}
	if (String(kindFacet).trim() !== '') {
		out.kind = String(kindFacet).trim()
		out.news = word(lang, 'news')
	}
	if (String(audienceFacet).trim() !== '') {
		out.audience = String(audienceFacet).trim()
	}
	return out
}

/**
 * How one facet is chosen from: the block's `facetDisplay` for its label,
 * else checkboxes.
 *
 * @param {Record<string, string>} display Facet label to `checkbox`, `radio` or `select`.
 * @param {string} label The facet.
 * @return {string} One of FACET_CONTROLS.
 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
 */
export function facetControl(display, label) {
	const chosen = display && typeof display === 'object' ? display[label] : ''
	return FACET_CONTROLS.includes(chosen) ? chosen : 'checkbox'
}

/**
 * The facet choices with one facet set to a single value (a radio or a
 * menu); an empty value clears that facet.
 *
 * @param {Record<string, Array<string>>} filters The choices.
 * @param {string} label The facet.
 * @param {string} value The value, or '' for all.
 * @return {Record<string, Array<string>>}
 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
 */
export function chooseOne(filters, label, value) {
	const next = { ...(filters || {}) }
	if (String(value || '') === '') {
		delete next[label]
	} else {
		next[label] = [String(value)]
	}
	return next
}

/**
 * The lines of a card in the `meta` style (boards Opleidingen and
 * Cursusaanbod): no kind label above the title; under the summary the first
 * meta part as a label ("Niveau 4", "Basis"), then the rest.
 *
 * @param {{meta: Array<string>}} card The card.
 * @return {{pill: string, rest: Array<string>}}
 * @spec openspec/changes/site-catalogue-follows-the-school-boards/specs/portaliq-cms/spec.md#requirement-the-catalogue-block-reads-like-the-search-boards
 */
export function metaLine(card) {
	const meta = Array.isArray(card?.meta) ? card.meta.filter(Boolean) : []
	return { pill: meta[0] || '', rest: meta.slice(1) }
}
