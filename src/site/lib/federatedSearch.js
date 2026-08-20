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

	url.searchParams.set(`_facets[${state.facetField}][type]`, 'terms')

	for (const value of state.selectedFacets || []) {
		url.searchParams.append(state.facetField, value)
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
