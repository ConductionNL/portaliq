// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A portal's public catalogue over the headless content contract
// (`/api/content/catalogue`, portal-public-catalogue): its public news and
// every app's public index, searched, filtered and paged by the server.
// Loaded only by the widgets that read it, so the site entry carries none of it.
//
// @spec openspec/changes/portal-public-catalogue/specs/portal-public-catalogue/spec.md#requirement-a-visitor-may-search-and-filter-a-portals-public-catalogue

import { adoptSessionToken } from './authApi.js'
import { resolveApiBase } from './contentApi.js'

/**
 * One page of the catalogue.
 *
 * @param {string} portal The portal slug.
 * @param {object} query The query.
 * @param {string} [query.q] The words searched for.
 * @param {Array<string>} [query.types] Only these item types.
 * @param {Record<string, Array<string>>} [query.filters] The facet choices.
 * @param {string} [query.sort] `relevance`, `date`, `dateDesc` or `title`.
 * @param {number} [query.page] The page, from 1.
 * @param {number} [query.limit] Results per page.
 * @param {boolean} [query.upcoming] Only what is still to come.
 * @return {Promise<{items: Array<object>, total: number, page: number, pages: number, facets: Array<object>}>}
 */
export async function fetchCatalogue(portal, query = {}) {
	const url = new URL(resolveApiBase() + '/catalogue', window.location.origin)
	const params = {
		portal,
		search: query.q || '',
		types: (query.types || []).join(','),
		filters:
			query.filters && Object.keys(query.filters).length > 0
				? JSON.stringify(query.filters)
				: '',
		sort: query.sort || '',
		page: query.page || '',
		limit: query.limit || '',
		upcoming: query.upcoming ? '1' : '',
	}
	for (const [key, value] of Object.entries(params)) {
		if (value !== undefined && value !== null && value !== '') {
			url.searchParams.set(key, String(value))
		}
	}
	const headers = { Accept: 'application/json' }
	const token = adoptSessionToken()
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	const response = await fetch(url.toString(), { headers })
	if (!response.ok) {
		const error = new Error(`content api ${response.status} for /catalogue`)
		error.status = response.status
		throw error
	}
	const body = await response.json()
	return {
		items: Array.isArray(body?.items) ? body.items : [],
		total: Number(body?.total) || 0,
		page: Number(body?.page) || 1,
		pages: Number(body?.pages) || 1,
		facets: Array.isArray(body?.facets) ? body.facets : [],
	}
}
