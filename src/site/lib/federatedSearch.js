// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Pure helpers behind the public federated-publication search.
 *
 * Extracted from the component for the same reason `authApi.js` is: this app
 * has no JS test runner, so anything that must be asserted has to be a
 * function a plain node script can import. What is left in the `.vue` file is
 * lifecycle and rendering.
 *
 * @spec openspec/changes/portal-federated-search/specs/portal-federated-search/spec.md#requirement-an-anonymous-visitor-must-be-able-to-search-federated-publications
 */

/**
 * The request URL for one search state.
 *
 * The query-parameter names are OpenCatalogi's own (`_search`, `_page`,
 * `_limit`), reused verbatim in the portal's URL so that a link a visitor
 * shares, a request a developer curls and the reference portal's `?_page=1`
 * all describe the same thing.
 *
 * FACETS TRAVEL WITH THE RESULTS, in one request. Asking for them separately
 * doubles the query count on every page view, and facet composition is
 * already the expensive half of the call.
 *
 * @param {object} state              The search state.
 * @param {string} state.endpoint     Endpoint path or absolute URL.
 * @param {string} state.origin       Origin to resolve a relative endpoint against.
 * @param {number} state.pageSize     Results per page.
 * @param {number} state.page         1-based page number.
 * @param {string} state.query        Free-text term, may be empty.
 * @param {string} state.facetField   Object field to facet on.
 * @param {Array<string>} state.selectedFacets Selected facet values.
 * @return {string} The absolute request URL.
 */
export function buildRequestUrl(state) {
	const url = new URL(state.endpoint, state.origin)

	url.searchParams.set('_limit', String(state.pageSize))
	url.searchParams.set('_page', String(state.page))

	// An empty term is OMITTED rather than sent as `_search=`. The two are not
	// the same request: one asks for everything, the other asks the backend to
	// match the empty string, and which of those a given backend does is not
	// something this portal should be betting on.
	if (state.query) {
		url.searchParams.set('_search', state.query)
	}

	// ONE FACET PER FIELD, IN THE SAME REQUEST (woo-search-and-detail D1).
	// `facetFields` with a `facets` map is the current shape; a caller that
	// still passes the single `facetField` with `selectedFacets` gets exactly
	// the request it got before.
	const fields = facetFieldsOf(state)
	for (const field of fields) {
		url.searchParams.set(`_facets[${field}][type]`, 'terms')
	}

	const selected = state.facets || { [fields[0]]: state.selectedFacets || [] }
	for (const field of fields) {
		for (const value of selected[field] || []) {
			url.searchParams.append(field, value)
		}
	}

	// THE PERIOD AS A RANGE (D2). OpenRegister reads `<field>[gte]` and
	// `<field>[lte]`; the to date covers its whole day. A date that does not
	// parse is left out: a filter nobody can read back is not sent.
	const periodField = state.periodField || 'publicationDate'
	const from = validDate(state.periodFrom)
	const to = validDate(state.periodTo)
	if (from) {
		url.searchParams.set(`${periodField}[gte]`, from)
	}
	if (to) {
		url.searchParams.set(`${periodField}[lte]`, `${to}T23:59:59Z`)
	}

	// `_order[<field>]=ASC|DESC`. Verified against the live endpoint on
	// 2026-08-20: title ASC/DESC and publicationDate ASC/DESC each return a
	// different first row, so the control changes something.
	//
	// NO "most relevant" OPTION. The reference portal offers one; nothing in
	// this API implements relevance ordering, and a sort option that silently
	// does nothing is the same class of defect as the directory filter that
	// answers `total: 0`.
	if (state.sort) {
		const [field, direction] = String(state.sort).split(':')
		if (field && direction) {
			url.searchParams.set(`_order[${field}]`, direction)
		}
	}

	return url.toString()
}

/**
 * Flatten one API row into what the list renders.
 *
 * Read defensively at every field, because this envelope is assembled from
 * FEDERATED peers: a row can legitimately arrive from an instance running an
 * older schema, and one such row must degrade to a title rather than blank
 * the list for everything behind it.
 *
 * @param {object} row One API result.
 * @return {object} The view model.
 */
export function toResult(row) {
	const self = (row || {})['@self'] || {}
	const summary = self.summary || (row || {}).description || ''

	return {
		key: self.id || (row || {}).id || (row || {}).sha || (row || {}).name || '',
		title: (row || {}).name || self.name || self.title || 'Zonder titel',
		// Truncated here rather than by CSS: an ellipsis that hides text still
		// ships every byte of it to a mobile connection.
		summary: typeof summary === 'string' ? summary.slice(0, 280) : '',
		href: (row || {}).landingUrl || (row || {}).url || self.uri || '',
		// `local` is the API's own word for a row this instance owns, so an
		// absent directory is named rather than left blank.
		directory: self.directory || 'local',
		// The id the portal's own detail route addresses. Falls back through
		// the same chain as `key` so a row from an older peer still links.
		id: self.id || (row || {}).id || '',
		date: formatDutchDate((row || {}).publicationDate || self.published || ''),
		// THE TYPE IS OFTEN NOT KNOWABLE FROM THIS ENVELOPE.
		//
		// The reference shows a type chip reading "Publiccode" — the SCHEMA
		// TITLE. This API returns `@self.schema` as a numeric id (17) and no
		// title, so rendering it would put "17" on every card. Empty means the
		// component omits the chip rather than showing a number nobody can
		// read; a page that knows its corpus supplies `typeLabel` instead.
		type: self.schemaTitle || '',
	}
}

/**
 * Normalise the facet envelope into buckets.
 *
 * TWO SHAPES ARRIVE ON THE SAME ENDPOINT and both are real:
 *
 *   object / metadata field → {data: {buckets: [{value, count, label}]}}
 *   OpenCatalogi virtual    → {buckets: [{key, results, label}]}
 *
 * Measured on 2026-08-20 against `/api/federation/publications`:
 * `_facets[categories][type]=terms` answers in the first shape,
 * `_facets[@self][directory][type]=terms` in the second. Reading only one of
 * them produces an empty column, which on screen is indistinguishable from
 * "this field has no values" — the failure names itself as data rather than
 * as a bug.
 *
 * @param {object} facets     The `facets` envelope from the API.
 * @param {string} facetField The field whose buckets are wanted.
 * @return {Array<object>} Normalised `{value, label, count}` buckets.
 */
export function toBuckets(facets, facetField) {
	const facet = (facets || {})[facetField] || {}
	const raw = (facet.data || {}).buckets || facet.buckets || []

	return raw
		.map((bucket) => ({
			value: String(bucket.value ?? bucket.key ?? ''),
			label: String(bucket.label ?? bucket.value ?? bucket.key ?? ''),
			count: Number(bucket.count ?? bucket.results ?? 0),
		}))
		.filter((bucket) => bucket.value !== '')
}

/**
 * The page numbers to offer around the current one.
 *
 * Windowed, because the publiccode corpus alone paginates to 356 pages and a
 * list of 356 links is not a navigation aid.
 *
 * @param {number} page  Current 1-based page.
 * @param {number} pages Total pages.
 * @param {number} span  How many neighbours either side.
 * @return {Array<number>} The page numbers.
 */
export function pageWindow(page, pages, span = 2) {
	const first = Math.max(1, page - span)
	const last = Math.min(pages, page + span)
	const out = []

	for (let index = first; index <= last; index++) {
		out.push(index)
	}

	return out
}

/**
 * The pagination row, including gap markers.
 *
 * Shaped to match the reference portal, measured on opencatalogi.nl at page 1
 * of 36: `1 2 3 4 5 … 36`. So: a run around the current page, the FIRST and
 * LAST page always reachable, and an ellipsis wherever the sequence skips.
 *
 * Returns numbers and the string `'gap'` rather than pre-rendered markup, so
 * the component decides what a gap looks like and this stays testable.
 *
 * @param {number} page  Current 1-based page.
 * @param {number} pages Total pages.
 * @return {Array<number|string>} Page numbers interleaved with `'gap'`.
 */
export function paginationItems(page, pages) {
	if (pages <= 1) {
		return [1]
	}

	const shown = new Set([1, pages])

	// Five consecutive pages at the start, matching the reference; once the
	// visitor is past that, a symmetric window around where they actually are.
	const start = page <= 4 ? 1 : page - 1
	const end = page <= 4 ? Math.min(5, pages) : Math.min(page + 1, pages)

	for (let index = start; index <= end; index++) {
		shown.add(index)
	}

	const ordered = [...shown]
		.filter((n) => n >= 1 && n <= pages)
		.sort((a, b) => a - b)
	const out = []

	for (const [index, number] of ordered.entries()) {
		// A gap marker only where the sequence actually skips. Emitting one for
		// a single missing page would be longer than the page it replaces.
		if (index > 0 && number - ordered[index - 1] > 1) {
			out.push('gap')
		}
		out.push(number)
	}

	return out
}

/**
 * Format an ISO date the way the reference portal does.
 *
 * `30 januari 2026` — day, Dutch month name, year. Built from a fixed table
 * rather than `toLocaleDateString`, because this renders at a public origin
 * where the visitor's locale decides what that function returns: the same
 * publication would read "January 30, 2026" for a visitor whose browser is set
 * to en-US, on a page that is otherwise entirely in Dutch.
 *
 * @param {string} value An ISO 8601 date string.
 * @return {string} The formatted date, or '' when it does not parse.
 */
export function formatDutchDate(value) {
	if (!value) {
		return ''
	}

	const parsed = new Date(value)
	if (Number.isNaN(parsed.getTime()) === true) {
		return ''
	}

	const months = [
		'januari',
		'februari',
		'maart',
		'april',
		'mei',
		'juni',
		'juli',
		'augustus',
		'september',
		'oktober',
		'november',
		'december',
	]

	return `${parsed.getUTCDate()} ${months[parsed.getUTCMonth()]} ${parsed.getUTCFullYear()}`
}

/**
 * The facet fields of a search state, oldest shape included.
 *
 * @param {object} state The search state.
 * @return {Array<string>} The field names, never empty.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-the-search-block-must-filter-on-information-category-and-organisation-req-wsd-001
 */
export function facetFieldsOf(state) {
	const fields = (
		state && Array.isArray(state.facetFields) ? state.facetFields : []
	).filter((field) => typeof field === 'string' && field !== '')
	if (fields.length > 0) {
		return fields
	}

	return [(state && state.facetField) || 'themes']
}

/**
 * A calendar date as `YYYY-MM-DD`, or '' when the value is not one.
 *
 * @param {string} value The value from an input or the page address.
 * @return {string} The date, or ''.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-the-search-block-must-filter-on-a-publication-period-req-wsd-002
 */
export function validDate(value) {
	const text = String(value || '')
	if (/^\d{4}-\d{2}-\d{2}$/.test(text) === false) {
		return ''
	}

	const parsed = new Date(`${text}T00:00:00Z`)
	if (Number.isNaN(parsed.getTime()) === true) {
		return ''
	}

	// `2026-02-31` parses and rolls over to March; that is not the date typed.
	return parsed.toISOString().slice(0, 10) === text ? text : ''
}

/**
 * Read the search state from a page address query string.
 *
 * Each facet field has its own `f.<field>` parameter. The older `_facets`
 * parameter is still read, as values of the FIRST field, so a link shared
 * before the filters split keeps opening the same search.
 *
 * @param {string}        search      `window.location.search`.
 * @param {Array<string>} facetFields The block's facet fields.
 * @return {object} `{query, page, sort, facets, periodFrom, periodTo}`.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-every-filter-must-survive-a-shared-link-req-wsd-003
 */
export function readSearchState(search, facetFields) {
	const params = new URLSearchParams(search || '')
	const split = (value) => (value ? value.split(',').filter(Boolean) : [])
	const facets = {}

	for (const field of facetFields) {
		facets[field] = split(params.get(`f.${field}`))
	}

	const legacy = split(params.get('_facets'))
	if (
		legacy.length > 0
		&& facetFields.length > 0
		&& facets[facetFields[0]].length === 0
	) {
		facets[facetFields[0]] = legacy
	}

	return {
		query: params.get('_search') || '',
		page: Math.max(1, parseInt(params.get('_page'), 10) || 1),
		sort: params.get('_sort') || '',
		facets,
		periodFrom: validDate(params.get('periodFrom')),
		periodTo: validDate(params.get('periodTo')),
	}
}

/**
 * Write the search state into a page address.
 *
 * `route` and every parameter this block does not own are left alone.
 *
 * @param {URL}           url         The address to change in place.
 * @param {object}        state       The search state.
 * @param {Array<string>} facetFields The block's facet fields.
 * @return {URL} The same address.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-every-filter-must-survive-a-shared-link-req-wsd-003
 */
export function writeSearchState(url, state, facetFields) {
	const assign = (key, value) => {
		if (value) {
			url.searchParams.set(key, value)
		} else {
			url.searchParams.delete(key)
		}
	}

	assign('_search', state.query)
	assign('_page', state.page > 1 ? String(state.page) : '')
	assign('_sort', state.sort)
	url.searchParams.delete('_facets')
	for (const field of facetFields) {
		assign(`f.${field}`, ((state.facets || {})[field] || []).join(','))
	}
	assign('periodFrom', validDate(state.periodFrom))
	assign('periodTo', validDate(state.periodTo))

	return url
}

/**
 * The search as the saved-search query of contract C2.
 *
 * `wooCategory` and `organization` are the publication schema's field names;
 * C2 (and opencatalogi's `GET /api/search`) call the same filters
 * `informatiecategorie` and `organisation`. Any other facet field is not part of C2 and is left
 * out rather than invented.
 *
 * @param {object} state   The search state.
 * @param {string} catalog The catalog the block searches, or ''.
 * @return {object} `{text, filters: {informatiecategorie, organisation, periodFrom, periodTo}, catalog}`.
 *
 * @spec openspec/changes/woo-search-and-detail/specs/portal-federated-search/spec.md#requirement-the-block-must-describe-its-search-as-the-saved-search-query-req-wsd-004
 */
export function searchQuery(state, catalog = '') {
	const facets = (state && state.facets) || {}

	return {
		text: String((state && state.query) || ''),
		filters: {
			informatiecategorie: [
				...(facets.wooCategory || facets.informatiecategorie || []),
			],
			organisation: [...(facets.organization || facets.organisation || [])],
			periodFrom: validDate(state && state.periodFrom),
			periodTo: validDate(state && state.periodTo),
		},
		catalog: String(catalog || ''),
	}
}
